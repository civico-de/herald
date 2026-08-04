<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

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
 * Beyond the bare ok↔fail edge this monitor also applies, all opt-in and
 * backwards-compatible (defaults reproduce the old one-shot behaviour):
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
 * Known blind spot by design: this runs inside the instance, so a hard-down
 * site cannot report itself. Pair it with an external poller on the pulse
 * endpoint for dead-man coverage; this monitor handles the degraded states.
 *
 * This class is the Civi-facing shell: locking, settings, publishing, state
 * persistence. Observation gathering lives in CheckCollector, the state
 * machine in TransitionPlanner.
 */
class Monitor {

  public const STATE_SETTING = 'herald_monitor_state';
  public const TOPIC_SETTING = 'herald_monitor_topic';
  public const LOCK_NAME = 'worker.herald.monitor';

  /**
   * Collect, execute, diff against last run, publish transitions, save state.
   *
   * Concurrency: guarded by Civi's lock framework so two overlapping crons
   * cannot double-send. Persistence: the new per-check state is written
   * INCREMENTALLY — right after each check's publish succeeds — so a crash or
   * a failing publish mid-batch never re-sends the transitions that already
   * went out on the next run.
   *
   * Known limitation (deliberate): this is at-least-once, not exactly-once —
   * a crash in the instant BETWEEN a successful publish and its state write
   * re-sends that one transition next run. True exactly-once needs a
   * persistent outbox with idempotent delivery; for ops alerts a rare
   * duplicate beats the complexity (and beats losing an alert, which is what
   * the reverse write-then-publish order would risk).
   *
   * @return array{checks?: int, failing?: int, transitions?: int, skipped_locked?: bool}
   */
  public function run(): array {
    // LOCK: skip the whole run if another cron already holds it, rather than
    // block — a duplicate monitor pass is worthless, not worth waiting for.
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
   * The unlocked body of run(). Split out so run() stays a thin lock wrapper.
   *
   * @return array{checks: int, failing: int, transitions: int}
   */
  private function doRun(): array {
    $observations = (new CheckCollector())->observe();

    // Written by this class only (see the state entry schema on
    // TransitionPlanner), which is why the shape can be asserted here; every
    // individual field is still read defensively over there.
    $prevRaw = \Civi::settings()->get(self::STATE_SETTING);
    /** @var array<string, array<string, mixed>> $prev */
    $prev = is_array($prevRaw) ? $prevRaw : [];

    $plans = self::computeTransitions($prev, $observations, $this->runConfig());

    // Start from the previous state; drop vanished (and non-expected) checks
    // up front — uninstalling an extension is not an outage, so its stale
    // state entry just disappears.
    $state = array_intersect_key($prev, $observations);
    \Civi::settings()->set(self::STATE_SETTING, $state);

    return [
      'checks' => count($observations),
      'failing' => count(array_filter($observations, static fn (array $c) => $c['status'] === 'fail')),
      'transitions' => $this->publishPlans($plans, $state),
    ];
  }

  /**
   * Read the run's thresholds and snooze map, and expire lapsed snoozes.
   *
   * @return array{open_after: int, close_after: int, renotify_seconds: int, snooze: array<string,string>, now: int}
   */
  private function runConfig(): array {
    // Admin-editable map check-name => ISO datetime. Entries that are not
    // strings are ignored by both consumers below.
    $snoozeRaw = \Civi::settings()->get('herald_monitor_snooze');
    /** @var array<string, string> $snooze */
    $snooze = is_array($snoozeRaw) ? $snoozeRaw : [];
    $now = \CRM_Utils_Time::time();

    // Housekeeping: drop snooze entries whose deadline has passed so the
    // setting does not accumulate stale rows. A snooze that no longer
    // suppresses anything has no reason to linger.
    $liveSnooze = array_filter(
      $snooze,
      static fn ($iso) => is_string($iso) && ($t = strtotime($iso)) !== FALSE && $t > $now,
    );
    if ($liveSnooze !== $snooze) {
      \Civi::settings()->set('herald_monitor_snooze', $liveSnooze);
    }

    return [
      'open_after' => max(1, Coerce::int(\Civi::settings()->get('herald_monitor_open_after') ?: 1)),
      'close_after' => max(1, Coerce::int(\Civi::settings()->get('herald_monitor_close_after') ?: 1)),
      'renotify_seconds' => max(0, Coerce::int(\Civi::settings()->get('herald_monitor_renotify_hours') ?? 24)) * 3600,
      'snooze' => $snooze,
      'now' => $now,
    ];
  }

  /**
   * Publish the due messages, persisting each check's new state only after its
   * own send succeeded, so a later failure in the batch cannot resurrect an
   * already-sent alert.
   *
   * @param list<array{check: string, state: array<string, mixed>, publish: ?array<string, mixed>}> $plans
   * @param array<string, array<string, mixed>> $state The state written so far.
   * @return int The number of transitions actually published.
   */
  private function publishPlans(array $plans, array $state): int {
    $topic = Coerce::str(\Civi::settings()->get(self::TOPIC_SETTING) ?: 'ops', 'ops');
    $transitions = 0;

    foreach ($plans as $plan) {
      $name = $plan['check'];
      if ($plan['publish'] === NULL) {
        // No message: counters/detail advanced but nothing user-facing.
        // Safe to persist immediately.
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
        // Leave the old entry in place → the transition is retried next run.
        // Do not abort the batch; the other checks still deserve their alerts.
        \Civi::log()->warning('herald monitor: publish failed for check {check}: {msg}', [
          'check' => $name,
          'msg' => $e->getMessage(),
        ]);
      }
    }

    return $transitions;
  }

  /**
   * Plan the transitions for a set of observations. Pure; see
   * TransitionPlanner for the state machine and its state entry schema.
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
