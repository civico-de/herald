<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Core\Event\GenericHookEvent;
use CRM_Herald_ExtensionUtil as E;

/**
 * Collects hook_civicrm_heraldChecks observations; an expected check that is
 * not offered becomes a failure of its own.
 */
class CheckCollector {

  /**
   * @return array<string, array{status: string, detail: string}> Observations by check name.
   */
  public function observe(): array {
    $observations = array_map($this->observeOne(...), $this->checks());
    $this->addMissingExpected($observations);

    return $observations;
  }

  /**
   * Names of the checks the hook offers, without running any of them.
   *
   * @return list<string>
   */
  public function names(): array {
    return array_map('strval', array_keys($this->checks()));
  }

  /**
   * @return array<string, callable>
   */
  private function checks(): array {
    $checks = [];
    foreach ($this->contributedChecks() as $name => $check) {
      if (!is_string($name) || !is_callable($check)) {
        continue;
      }
      $checks[$name] = $check;
    }
    return $checks;
  }

  /**
   * Nothing enforces name => callable on hook implementors, hence the loose type.
   *
   * @return array<array-key, mixed>
   */
  private function contributedChecks(): array {
    /** @var array<array-key, mixed> $checks */
    $checks = [];
    \Civi::dispatcher()->dispatch('hook_civicrm_heraldChecks', GenericHookEvent::create(['checks' => &$checks]));

    return is_array($checks) ? $checks : [];
  }

  /**
   * A throwing callable counts as a fail.
   *
   * @return array{status: string, detail: string}
   */
  private function observeOne(callable $callable): array {
    try {
      $r = $callable();
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
   * An expected check the hook does not offer means its extension is probably disabled.
   *
   * @param array<string, array{status: string, detail: string}> $observations
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
