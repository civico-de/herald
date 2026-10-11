<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Generic health-check monitor: any extension contributes checks, herald
 * runs them on cron and publishes STATE TRANSITIONS (ok→fail, fail→ok) to a
 * topic — never the steady state, so a broken check alerts once and a
 * recovery closes the loop, without per-cron spam.
 *
 * Contributing checks (from any extension):
 *
 *   function myext_civicrm_heraldChecks(array &$checks): void {
 *     $checks['myext.queue'] = fn() => ['status' => 'ok'];           // or
 *     $checks['myext.api']   = fn() => ['status' => 'fail', 'detail' => '…'];
 *   }
 *
 * A check callable returns ['status' => 'ok'|'fail'|'skipped', 'detail' => ?].
 * A throwing callable counts as fail with the exception message as detail.
 *
 * Opt-in refinements (settings):
 *   - HYSTERESIS: require N consecutive fails / M consecutive oks before the
 *     transition is reported, to ride out flapping checks
 *     (herald_monitor_open_after / _close_after, both default 1);
 *   - RENOTIFY: re-announce a check stuck failing for X hours
 *     (herald_monitor_renotify_hours, default 24, 0 = off);
 *   - SNOOZE: silence named checks until a timestamp
 *     (herald_monitor_snooze, map check-name → ISO datetime);
 *   - MISSING: treat a check listed in herald_monitor_expected but absent
 *     from the hook as a failure (extension disabled?) instead of dropping it.
 *
 * Runs inside the instance, so a hard-down site cannot report itself; pair
 * with an external poller on the pulse endpoint.
 */
class Monitor {

  public const STATE_SETTING = 'herald_monitor_state';
  public const TOPIC_SETTING = 'herald_monitor_topic';
  public const LOCK_NAME = 'worker.herald.monitor';

  /**
   * At-least-once by design: state is persisted per check right after its
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
   * @return array{checks: int, failing: int, transitions: int, publish_failures: int}
   */
  private function doRun(): array {
    $observations = (new CheckCollector())->observe();

    $prevRaw = \Civi::settings()->get(self::STATE_SETTING);
    /** @var array<string, array<string, mixed>> $prev */
    $prev = is_array($prevRaw) ? $prevRaw : [];

    $plans = self::computeTransitions($prev, $observations, $this->runConfig());

    // Vanished checks are dropped: uninstalling an extension is not an outage.
    $state = array_intersect_key($prev, $observations);
    \Civi::settings()->set(self::STATE_SETTING, $state);

    [$published, $failed] = $this->publishPlans($plans, $state);

    return [
      'checks' => count($observations),
      'failing' => count(array_filter($observations, static fn (array $c) => $c['status'] === 'fail')),
      'transitions' => $published,
      'publish_failures' => $failed,
    ];
  }

  /**
   * Read the run's thresholds and snooze map, and expire lapsed snoozes.
   *
   * @return array{open_after: int, close_after: int, renotify_seconds: int, snooze: array<string,string>, now: int}
   */
  private function runConfig(): array {
    // Admin-editable map check-name => ISO datetime.
    $snoozeRaw = \Civi::settings()->get('herald_monitor_snooze');
    /** @var array<string, string> $snooze */
    $snooze = is_array($snoozeRaw) ? $snoozeRaw : [];
    $now = \CRM_Utils_Time::time();

    // Drop lapsed snoozes so the setting does not accumulate stale rows.
    $liveSnooze = array_filter(
      $snooze,
      static fn ($iso) => is_string($iso) && ($t = strtotime($iso)) !== FALSE && $t > $now,
    );
    if ($liveSnooze !== $snooze) {
      \Civi::settings()->set('herald_monitor_snooze', $liveSnooze);
    }

    return [
      'open_after' => max(1, Cast::toInt(\Civi::settings()->get('herald_monitor_open_after') ?: 1)),
      'close_after' => max(1, Cast::toInt(\Civi::settings()->get('herald_monitor_close_after') ?: 1)),
      'renotify_seconds' => max(0, Cast::toInt(\Civi::settings()->get('herald_monitor_renotify_hours') ?? 24)) * 3600,
      'snooze' => $snooze,
      'now' => $now,
    ];
  }

  /**
   * State is persisted per check only after its own send succeeded.
   *
   * @param list<array{check: string, state: array<string, mixed>, publish: ?array<string, mixed>}> $plans
   * @param array<string, array<string, mixed>> $state The state written so far.
   * @return array{int, int} Transitions published, transitions whose publish failed.
   */
  private function publishPlans(array $plans, array $state): array {
    $topic = Cast::toString(\Civi::settings()->get(self::TOPIC_SETTING) ?: 'ops', 'ops');
    $transitions = 0;
    $failures = 0;

    foreach ($plans as $plan) {
      $name = $plan['check'];
      if ($plan['publish'] === NULL) {
        $state[$name] = $plan['state'];
        \Civi::settings()->set(self::STATE_SETTING, $state);
        continue;
      }
      try {
        \Civi\Api4\Herald::publish(checkPermissions: FALSE)
          ->setTopic($topic)
          ->setTemplate('herald_monitor_alert')
          ->setVars($plan['publish'])
          ->execute();
        $state[$name] = $plan['state'];
        \Civi::settings()->set(self::STATE_SETTING, $state);
        $transitions++;
      }
      catch (\Throwable $e) {
        // Old entry stays → retried next run; the batch continues.
        $failures++;
        \Civi::log()->warning('herald monitor: publish failed for check {check}: {msg}', [
          'check' => $name,
          'msg' => $e->getMessage(),
        ]);
      }
    }

    return [$transitions, $failures];
  }

  /**
   * Pure; see TransitionPlanner.
   *
   * @param array<string, array<string, mixed>> $prev
   * @param array<string, array{status: string, detail?: string}> $observations
   * @param array{open_after?: int, close_after?: int, renotify_seconds?: int, snooze?: array<string,string>, now?: int} $config
   * @return list<array{check: string, state: array<string, mixed>, publish: ?array<string, mixed>}>
   */
  public static function computeTransitions(array $prev, array $observations, array $config = []): array {
    return (new TransitionPlanner($config))->plan($prev, $observations);
  }

}
