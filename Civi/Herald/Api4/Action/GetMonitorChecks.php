<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\MonitorChecks;

/**
 * The monitor's checks: provided by the hook, expected, snoozed, and their
 * stored counters. Calls no check and returns no detail text.
 *
 * Example:
 *   cv api4 Herald.getMonitorChecks
 */
class GetMonitorChecks extends AbstractAction {

  public function _run(Result $result): void {
    $result->exchangeArray(MonitorChecks::rows());
  }

}
