<?php

namespace Civi\Herald\Monitor;

use Civi\Core\Event\GenericHookEvent;

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
 * A check callable returns ['status' => 'ok'|'fail', 'detail' => string?].
 * A throwing callable counts as fail with the exception message as detail.
 *
 * Known blind spot by design: this runs inside the instance, so a hard-down
 * site cannot report itself. Pair it with an external poller on the pulse
 * endpoint for dead-man coverage; this monitor handles the degraded states.
 */
class Monitor {

  public const STATE_SETTING = 'herald_monitor_state';
  public const TOPIC_SETTING = 'herald_monitor_topic';

  /**
   * Collect, execute, diff against last run, publish transitions, save state.
   *
   * @return array{checks: int, failing: int, transitions: int}
   */
  public function run(): array {
    $checks = [];
    \Civi::dispatcher()->dispatch('hook_civicrm_heraldChecks', GenericHookEvent::create(['checks' => &$checks]));

    $current = [];
    foreach ($checks as $name => $callable) {
      if (!is_string($name) || !is_callable($callable)) {
        continue;
      }
      try {
        $r = $callable();
        // Anything that is not an explicit non-failure counts as fail;
        // 'skipped' (check disabled by config) is not an outage.
        $status = is_array($r) ? ($r['status'] ?? NULL) : NULL;
        $current[$name] = [
          'status' => in_array($status, ['ok', 'skipped'], TRUE) ? 'ok' : 'fail',
          'detail' => is_array($r) && is_scalar($r['detail'] ?? NULL) ? (string) $r['detail'] : '',
        ];
      }
      catch (\Throwable $e) {
        $current[$name] = ['status' => 'fail', 'detail' => $e->getMessage()];
      }
    }

    $prev = \Civi::settings()->get(self::STATE_SETTING);
    $transitions = self::computeTransitions(is_array($prev) ? $prev : [], $current);

    $topic = (string) (\Civi::settings()->get(self::TOPIC_SETTING) ?: 'ops');
    foreach ($transitions as $t) {
      \Civi\Api4\Herald::publish(FALSE)
        ->setTopic($topic)
        ->setTemplate('herald_monitor_alert')
        ->setVars($t)
        ->execute();
    }

    \Civi::settings()->set(self::STATE_SETTING, $current);

    return [
      'checks' => count($current),
      'failing' => count(array_filter($current, static fn(array $c) => $c['status'] === 'fail')),
      'transitions' => count($transitions),
    ];
  }

  /**
   * Diff previous vs current check states. Pure — test-seam.
   *
   * Alerts on ok→fail and fail→ok. A check seen for the FIRST time alerts
   * only when failing (its "ok" baseline is not news); a check that
   * disappears is silently dropped (extension uninstalled ≠ outage).
   *
   * @param array<string, array{status: string, detail?: string}> $prev
   * @param array<string, array{status: string, detail?: string}> $current
   * @return list<array{check: string, status: string, detail: string}>
   */
  public static function computeTransitions(array $prev, array $current): array {
    $transitions = [];
    foreach ($current as $name => $state) {
      $before = $prev[$name]['status'] ?? 'ok';
      if ($state['status'] !== $before) {
        $transitions[] = [
          'check' => $name,
          'status' => $state['status'],
          'detail' => $state['detail'] ?? '',
        ];
      }
    }
    return $transitions;
  }

}
