<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Monitor\Monitor;

/**
 * The check keys the next monitor run would announce, with status, title,
 * detail, text and severity. Sends nothing and writes no monitor setting;
 * System.check itself may write core's own records.
 *
 * Example:
 *   cv api4 Herald.monitorPreview
 */
class MonitorPreview extends AbstractAction {

  public function _run(Result $result): void {
    $result->exchangeArray((new Monitor())->preview());
  }

}
