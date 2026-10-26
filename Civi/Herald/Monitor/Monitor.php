<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Check monitor: observes core's status messages (System.check) per check
 * key on cron and publishes STATE TRANSITIONS (ok→fail, fail→ok, a visible
 * escalation or detail change) to a topic — never the steady state, so a
 * broken check alerts once and a recovery closes the loop.
 *
 * Extensions contribute checks through hook_civicrm_check. A message whose
 * severity reaches the threshold (herald_monitor_min_severity, exceptions in
 * herald_monitor_severity) is a failure; a key in the state without a message
 * this run has recovered.
 *
 * Opt-in refinements (settings):
 *   - HYSTERESIS: herald_monitor_open_after / _close_after (default 1);
 *   - RENOTIFY: herald_monitor_renotify_hours (default 24, 0 = off);
 *   - SNOOZE: herald_monitor_snooze, key or prefix → ISO datetime or '' (no end);
 *   - EXPECTED: herald_monitor_expected, extension keys that must be enabled.
 *
 * Runs inside the instance, so a hard-down site cannot report itself; pair
 * with an external uptime monitor.
 */
class Monitor {

  public const STATE_SETTING = 'herald_monitor_state';
  public const TOPIC_SETTING = 'herald_monitor_topic';
  public const LOCK_NAME = 'worker.herald.monitor';

  private CheckCollector $collector;

  public function __construct(?CheckCollector $collector = NULL) {
    $this->collector = $collector ?? new CheckCollector();
  }

  /**
   * At-least-once by design: state is persisted per key right after its
   * publish, so a crash in between re-sends that one transition next run.
   *
   * @return array{checks?: int, failing?: int, transitions?: int, publish_failures?: int, skipped_locked?: bool}
   */
  public function run(): array {
    // Skip rather than block: a duplicate monitor pass is worthless.
    $lock = \Civi::lockManager()->acquire(self::LOCK_NAME);
    if (!$lock->isAcquired()) {
      return ['skipped_locked' => TRUE];
    }

    try {
      return $this->doRun();
    }
    finally {
      $lock->release();
    }
  }

  /**
   * What the next run would announce; sends nothing and writes no monitor setting.
   *
   * @return list<array{check: string, status: string, title: string, detail: string, text: string, severity: string, renotify: bool}>
   */
  public function preview(): array {
    $now = \CRM_Utils_Time::time();
    $snooze = Snooze::live(self::arraySetting(Snooze::SETTING), $now);
    [, $plans] = $this->plan($snooze, $now);
    $rows = [];
    foreach ($plans as $plan) {
      if ($plan['publish'] === NULL) {
        continue;
      }
      $status = Cast::toString($plan['publish']['status']);
      $rows[] = [
        'check' => $plan['check'],
        'status' => $status,
        'title' => Cast::toString($plan['publish']['title']),
        'detail' => Cast::toString($plan['publish']['detail']),
        'text' => Cast::toString($plan['publish']['text']),
        'severity' => $status === 'ok' ? '' : Severity::name(Cast::toInt($plan['publish']['severity'])),
        'renotify' => !empty($plan['publish']['renotify']),
      ];
    }
    return $rows;
  }

  /**
   * @return array{checks: int, failing: int, transitions: int, publish_failures: int}
   */
  private function doRun(): array {
    $now = \CRM_Utils_Time::time();
    $snoozeRaw = self::arraySetting(Snooze::SETTING);
    // Lapsed and malformed snoozes are dropped so the setting does not accumulate stale rows.
    $snooze = Snooze::live($snoozeRaw, $now);
    if ($snooze !== $snoozeRaw) {
      \Civi::settings()->set(Snooze::SETTING, $snooze);
    }

    [$observations, $plans] = $this->plan($snooze, $now);
    [$published, $failed] = $this->publishPlans($plans);

    return [
      'checks' => count($observations),
      'failing' => count(array_filter($observations, static fn (array $o): bool => $o['status'] === 'fail')),
      'transitions' => $published,
      'publish_failures' => $failed,
    ];
  }

  /**
   * @param array<string, string> $snooze
   * @return array{0: array<string, array{status: string, severity: int, detail: list<string>, title: string, text: string, visible: bool}>, 1: list<array{check: string, state: ?array<string, mixed>, publish: ?array<string, mixed>}>}
   */
  private function plan(array $snooze, int $now): array {
    $observations = $this->collector->observe(Threshold::fromSettings());
    $plans = self::computeTransitions(self::state(), $observations, [
      'open_after' => max(1, Cast::toInt(\Civi::settings()->get('herald_monitor_open_after') ?: 1)),
      'close_after' => max(1, Cast::toInt(\Civi::settings()->get('herald_monitor_close_after') ?: 1)),
      'renotify_seconds' => max(0, Cast::toInt(\Civi::settings()->get('herald_monitor_renotify_hours') ?? 24)) * 3600,
      'snooze' => $snooze,
      'now' => $now,
    ]);
    return [$observations, $plans];
  }

  /**
   * State is persisted per key only after its own send succeeded.
   *
   * @param list<array{check: string, state: ?array<string, mixed>, publish: ?array<string, mixed>}> $plans
   * @return array{int, int} Transitions published, transitions whose publish failed.
   */
  private function publishPlans(array $plans): array {
    $topic = Cast::toString(\Civi::settings()->get(self::TOPIC_SETTING) ?: 'ops', 'ops');
    $state = self::state();
    $transitions = 0;
    $failures = 0;

    foreach ($plans as $plan) {
      $key = $plan['check'];
      if ($plan['publish'] !== NULL) {
        try {
          \Civi\Api4\Herald::publish(checkPermissions: FALSE)
            ->setTopic($topic)
            ->setTemplate('herald_monitor_alert')
            ->setVars(['severity' => Severity::label(Cast::toInt($plan['publish']['severity']))] + $plan['publish'])
            ->execute();
          $transitions++;
        }
        catch (\Throwable $e) {
          // Old entry stays → retried next run; the batch continues.
          $failures++;
          \Civi::log()->warning('herald monitor: publish failed for check {check}: {msg}', [
            'check' => $key,
            'msg' => $e->getMessage(),
          ]);
          continue;
        }
      }
      unset($state[$key]);
      if ($plan['state'] !== NULL) {
        $state[$key] = $plan['state'];
      }
      \Civi::settings()->set(self::STATE_SETTING, $state);
    }

    return [$transitions, $failures];
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private static function state(): array {
    /** @var array<string, array<string, mixed>> */
    return array_filter(self::arraySetting(self::STATE_SETTING), 'is_array');
  }

  /**
   * @return array<array-key, mixed>
   */
  private static function arraySetting(string $name): array {
    $value = \Civi::settings()->get($name);
    return is_array($value) ? $value : [];
  }

  /**
   * Pure; see TransitionPlanner.
   *
   * @param array<string, array<string, mixed>> $prev
   * @param array<string, array<string, mixed>> $observations
   * @param array{open_after?: int, close_after?: int, renotify_seconds?: int, snooze?: array<string,string>, now?: int} $config
   * @return list<array{check: string, state: ?array<string, mixed>, publish: ?array<string, mixed>}>
   */
  public static function computeTransitions(array $prev, array $observations, array $config = []): array {
    return (new TransitionPlanner($config))->plan($prev, $observations);
  }

}
