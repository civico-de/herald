<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\MonitorSettings;

/**
 * Replace the expected-check list and the snooze map; the monitor state stays untouched.
 *
 * Example:
 *   cv api4 Herald.saveMonitorChecks 'expected=["mail.bounces"]' 'snooze={"mail.bounces":"2026-12-01T08:00"}'
 *
 * @method $this setExpected(list<string> $expected)
 * @method $this setSnooze(array<string, string> $snooze)
 */
class SaveMonitorChecks extends AbstractAction {

  /**
   * Check names that must always be present.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $expected = [];

  /**
   * Check name => datetime until which it does not alert; '' lifts a snooze.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $snooze = [];

  public function _run(Result $result): void {
    $result[] = MonitorSettings::save($this->expected, $this->snooze);
  }

}
