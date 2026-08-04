<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Core\Event\GenericHookEvent;
use CRM_Herald_ExtensionUtil as E;

/**
 * Gathers this run's raw observations: asks every extension for its checks,
 * executes them, and turns a check that was expected but never offered into a
 * failure of its own.
 */
class CheckCollector {

  /**
   * Run every contributed check.
   *
   * @return array<string, array{status: string, detail: string}> Observations by check name.
   */
  public function observe(): array {
    $observations = [];
    foreach ($this->contributedChecks() as $name => $callable) {
      if (!is_string($name) || !is_callable($callable)) {
        continue;
      }
      $observations[$name] = $this->observeOne($callable);
    }

    $this->addMissingExpected($observations);

    return $observations;
  }

  /**
   * Ask every extension for its checks.
   *
   * Filled BY REFERENCE by the hook listeners — implementors are asked for
   * name => callable, but nothing enforces that, hence the loose type.
   *
   * @return array<array-key, mixed> The contributed checks.
   */
  private function contributedChecks(): array {
    /** @var array<array-key, mixed> $checks */
    $checks = [];
    \Civi::dispatcher()->dispatch('hook_civicrm_heraldChecks', GenericHookEvent::create(['checks' => &$checks]));

    return $checks;
  }

  /**
   * Execute one check callable. A throwing callable counts as a fail.
   *
   * @param callable $callable The check.
   * @return array{status: string, detail: string} The observation.
   */
  private function observeOne(callable $callable): array {
    try {
      $r = $callable();
      // Anything that is not an explicit non-failure counts as fail;
      // 'skipped' (check disabled by config) is not an outage.
      $status = is_array($r) ? $r['status'] ?? NULL : NULL;

      return [
        'status' => in_array($status, ['ok', 'skipped'], TRUE) ? 'ok' : 'fail',
        'detail' => is_array($r) && is_scalar($r['detail'] ?? NULL) ? (string) $r['detail'] : '',
      ];
    }
    catch (\Throwable $e) {
      return ['status' => 'fail', 'detail' => $e->getMessage()];
    }
  }

  /**
   * A check we were told to expect but the hook no longer offers is itself a
   * failure (its extension was probably disabled). Without an expected-entry a
   * vanished check stays silently dropped.
   *
   * @param array<string, array{status: string, detail: string}> $observations
   *   Observations, updated in place.
   */
  private function addMissingExpected(array &$observations): void {
    $expected = \Civi::settings()->get('herald_monitor_expected');
    foreach (is_array($expected) ? $expected : [] as $name) {
      if (!(is_string($name) && !isset($observations[$name]))) {
        continue;
      }

      $observations[$name] = [
        'status' => 'fail',
        'detail' => E::ts('check missing — extension disabled?'),
      ];
    }
  }

}
