<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\MonitorSettings;

/**
 * Replace the monitor threshold, its exceptions, the snoozes and the expected
 * extensions; the monitor state stays untouched.
 *
 * Example:
 *   cv api4 Herald.saveMonitorChecks minSeverity=warning 'severity={"warden_*":"error"}' \
 *     'snooze={"checkExtensionsRequired":"2026-12-01T08:00","sentinel_*":""}' 'expected=["pulse"]'
 *
 * @method $this setMinSeverity(string $minSeverity)
 * @method $this setSeverity(array<string, string> $severity)
 * @method $this setSnooze(array<string, string> $snooze)
 * @method $this setExpected(list<string> $expected)
 */
class SaveMonitorChecks extends AbstractAction {

  /**
   * Level from which a status message counts as failing; notice at the lowest.
   * @var string
   * @required
   */
  protected string $minSeverity = '';

  /**
   * Check key or prefix ending in * => level, overriding the threshold.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $severity = [];

  /**
   * Check key or prefix ending in * => datetime until which it does not alert; '' for no end.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $snooze = [];

  /**
   * Extension keys that must be installed and enabled.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $expected = [];

  public function _run(Result $result): void {
    $result[] = MonitorSettings::save($this->minSeverity, $this->severity, $this->snooze, $this->expected);
  }

}
