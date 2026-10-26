<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\MonitorChecks;

/**
 * The monitor screen in one record: the check keys System.check reports and
 * the keys in the monitor state, with detail, severity, visibility and
 * monitor state, plus the threshold, snooze and expected settings. Runs
 * System.check, returns no message text and writes nothing.
 *
 * Example:
 *   cv api4 Herald.getMonitorChecks
 */
class GetMonitorChecks extends AbstractAction {

  public function _run(Result $result): void {
    $result[] = MonitorChecks::screen();
  }

}
