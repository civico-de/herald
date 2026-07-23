<?php

namespace Civi\Herald\Monitor;

use Civi\Core\Event\GenericHookEvent;
use CRM_Herald_ExtensionUtil as E;

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
    $checks = [];
    \Civi::dispatcher()->dispatch('hook_civicrm_heraldChecks', GenericHookEvent::create(['checks' => &$checks]));

    $observations = [];
    foreach ($checks as $name => $callable) {
      if (!is_string($name) || !is_callable($callable)) {
        continue;
      }
      try {
        $r = $callable();
        // Anything that is not an explicit non-failure counts as fail;
        // 'skipped' (check disabled by config) is not an outage.
        $status = is_array($r) ? ($r['status'] ?? NULL) : NULL;
        $observations[$name] = [
          'status' => in_array($status, ['ok', 'skipped'], TRUE) ? 'ok' : 'fail',
          'detail' => is_array($r) && is_scalar($r['detail'] ?? NULL) ? (string) $r['detail'] : '',
        ];
      }
      catch (\Throwable $e) {
        $observations[$name] = ['status' => 'fail', 'detail' => $e->getMessage()];
      }
    }

    // MISSING: a check we were told to expect but the hook no longer offers is
    // itself a failure (its extension was probably disabled). Without an
    // expected-entry a vanished check stays silently dropped (see below).
    $expected = \Civi::settings()->get('herald_monitor_expected');
    foreach (is_array($expected) ? $expected : [] as $name) {
      if (is_string($name) && !isset($observations[$name])) {
        $observations[$name] = [
          'status' => 'fail',
          'detail' => E::ts('check missing — extension disabled?'),
        ];
      }
    }

    $prev = \Civi::settings()->get(self::STATE_SETTING);
    $prev = is_array($prev) ? $prev : [];

    $config = [
      'open_after' => max(1, (int) (\Civi::settings()->get('herald_monitor_open_after') ?: 1)),
      'close_after' => max(1, (int) (\Civi::settings()->get('herald_monitor_close_after') ?: 1)),
      'renotify_seconds' => max(0, (int) (\Civi::settings()->get('herald_monitor_renotify_hours') ?? 24)) * 3600,
      'snooze' => is_array($s = \Civi::settings()->get('herald_monitor_snooze')) ? $s : [],
      'now' => \CRM_Utils_Time::time(),
    ];

    // Housekeeping: drop snooze entries whose deadline has passed so the
    // setting does not accumulate stale rows. A snooze that no longer suppresses
    // anything has no reason to linger.
    $liveSnooze = array_filter(
      $config['snooze'],
      static fn($iso) => is_string($iso) && ($t = strtotime($iso)) !== FALSE && $t > $config['now']
    );
    if ($liveSnooze !== $config['snooze']) {
      \Civi::settings()->set('herald_monitor_snooze', $liveSnooze);
    }

    $plans = self::computeTransitions($prev, $observations, $config);

    // Start from the previous state; mutate + persist per check so a partial
    // run is always consistent with what has actually been published.
    $state = $prev;

    // Drop vanished (and non-expected) checks up front — uninstalling an
    // extension is not an outage, so its stale state entry just disappears.
    foreach (array_keys($state) as $name) {
      if (!isset($observations[$name])) {
        unset($state[$name]);
      }
    }
    \Civi::settings()->set(self::STATE_SETTING, $state);

    $topic = (string) (\Civi::settings()->get(self::TOPIC_SETTING) ?: 'ops');
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
        \Civi\Api4\Herald::publish(FALSE)
          ->setTopic($topic)
          ->setTemplate('herald_monitor_alert')
          ->setVars($plan['publish'])
          ->execute();
        // Persist THIS check's new state only after its send succeeded, so a
        // later failure in the batch cannot resurrect an already-sent alert.
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

    return [
      'checks' => count($observations),
      'failing' => count(array_filter($observations, static fn(array $c) => $c['status'] === 'fail')),
      'transitions' => $transitions,
    ];
  }

  /**
   * Pure transition planner — the test-seam. No Civi calls: everything it
   * needs (now, thresholds, snooze) is injected via $config.
   *
   * Per check it folds the raw observation into the persisted state machine
   * and decides whether a message is due. It returns, for every observed
   * check, a plan the thin Civi wrapper can act on one at a time:
   *
   *   ['check' => name, 'state' => <entry to persist>, 'publish' => <vars>|NULL]
   *
   * State entry schema (old entries without the extra keys are tolerated —
   * status defaults to 'ok', counters to 0, notified to false):
   *   status: 'ok'|'fail'  — last REPORTED state (the edge we alerted on)
   *   detail: string
   *   consecutive_fails / consecutive_oks: int  — raw observation run-lengths
   *   notified: bool        — did we actually send a fail alert this episode
   *   last_notified: int|null — timestamp of the last fail alert (for renotify)
   *
   * A check seen for the FIRST time baselines to reported-ok, so a healthy
   * newcomer is not news but a failing one still alerts once open_after is met.
   *
   * @param array<string, array<string, mixed>> $prev
   * @param array<string, array{status: string, detail?: string}> $observations
   * @param array{open_after?: int, close_after?: int, renotify_seconds?: int, snooze?: array<string,string>, now?: int} $config
   * @return list<array{check: string, state: array<string, mixed>, publish: ?array<string, mixed>}>
   */
  public static function computeTransitions(array $prev, array $observations, array $config = []): array {
    $openAfter = max(1, (int) ($config['open_after'] ?? 1));
    $closeAfter = max(1, (int) ($config['close_after'] ?? 1));
    $renotifySeconds = max(0, (int) ($config['renotify_seconds'] ?? 0));
    $snooze = $config['snooze'] ?? [];
    $now = (int) ($config['now'] ?? 0);

    $plans = [];
    foreach ($observations as $name => $obs) {
      $obsStatus = ($obs['status'] ?? 'ok') === 'fail' ? 'fail' : 'ok';
      $obsDetail = is_scalar($obs['detail'] ?? NULL) ? (string) $obs['detail'] : '';

      $p = $prev[$name] ?? [];
      $status = ($p['status'] ?? 'ok') === 'fail' ? 'fail' : 'ok';
      $detail = is_scalar($p['detail'] ?? NULL) ? (string) $p['detail'] : '';
      $cf = max(0, (int) ($p['consecutive_fails'] ?? 0));
      $co = max(0, (int) ($p['consecutive_oks'] ?? 0));
      // Migration: legacy state entries (bare {status, detail}) carry no
      // 'notified' flag. The old monitor always announced a fail, so infer a
      // legacy reported-fail as already-notified — otherwise it would re-alert
      // or, worse, swallow the recovery.
      $notified = (bool) ($p['notified'] ?? ($status === 'fail'));
      $lastNotified = isset($p['last_notified']) && is_numeric($p['last_notified']) ? (int) $p['last_notified'] : NULL;

      // Advance the raw run-length counters.
      if ($obsStatus === 'fail') {
        $cf++;
        $co = 0;
      }
      else {
        $co++;
        $cf = 0;
      }

      $snoozed = self::isSnoozed($name, $snooze, $now);
      $publish = NULL;

      if ($status === 'ok') {
        if ($cf >= $openAfter) {
          // Hysteresis met: report a fail edge — unless snoozed, in which case
          // we still flip the internal state but keep quiet (a later recovery
          // must not fire a fail→ok for an unannounced fail).
          $status = 'fail';
          $detail = $obsDetail;
          if ($snoozed) {
            $notified = FALSE;
            $lastNotified = NULL;
          }
          else {
            $notified = TRUE;
            $lastNotified = $now;
            $publish = ['check' => $name, 'status' => 'fail', 'detail' => $detail, 'renotify' => FALSE];
          }
        }
      }
      else {
        // Currently in a reported-fail episode.
        if ($co >= $closeAfter) {
          // Recovered. Only close the loop if we actually opened it loudly.
          $status = 'ok';
          $detail = $obsDetail;
          $wasNotified = $notified;
          $notified = FALSE;
          $lastNotified = NULL;
          if ($wasNotified) {
            $publish = ['check' => $name, 'status' => 'ok', 'detail' => $detail, 'renotify' => FALSE];
          }
        }
        else {
          // Still failing. Keep the freshest fail detail.
          if ($obsStatus === 'fail') {
            $detail = $obsDetail;
          }
          if (!$snoozed && !$notified) {
            // The fail was suppressed by a snooze that has since lapsed —
            // announce it now (delayed first alert).
            $notified = TRUE;
            $lastNotified = $now;
            $publish = ['check' => $name, 'status' => 'fail', 'detail' => $detail, 'renotify' => FALSE];
          }
          elseif (!$snoozed && $notified && $renotifySeconds > 0
            && $lastNotified !== NULL && ($now - $lastNotified) >= $renotifySeconds) {
            // Stuck failing past the renotify window: re-announce.
            $lastNotified = $now;
            $publish = ['check' => $name, 'status' => 'fail', 'detail' => $detail, 'renotify' => TRUE];
          }
        }
      }

      $plans[] = [
        'check' => $name,
        'state' => [
          'status' => $status,
          'detail' => $detail,
          'consecutive_fails' => $cf,
          'consecutive_oks' => $co,
          'notified' => $notified,
          'last_notified' => $lastNotified,
        ],
        'publish' => $publish,
      ];
    }

    return $plans;
  }

  /**
   * Is $name snoozed at $now? Snooze map holds ISO datetimes (until when).
   * Unparseable or past timestamps are treated as not snoozed. Pure.
   *
   * @param array<string, string> $snooze
   */
  private static function isSnoozed(string $name, array $snooze, int $now): bool {
    if (!isset($snooze[$name]) || !is_string($snooze[$name])) {
      return FALSE;
    }
    $until = strtotime($snooze[$name]);
    return $until !== FALSE && $now < $until;
  }

}
