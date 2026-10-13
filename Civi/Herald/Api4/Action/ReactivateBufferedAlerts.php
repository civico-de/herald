<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractBatchAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Herald;

/**
 * HeraldBufferedAlert.reactivate, the SearchKit task's entry point: hands the
 * selected rows to Herald.reactivateParkedAlerts. Waiting rows stay as they are.
 *
 * Example:
 *   cv api4 HeraldBufferedAlert.reactivate +w 'id IN [12,13]'
 */
class ReactivateBufferedAlerts extends AbstractBatchAction {

  public function _run(Result $result): void {
    $ids = [];
    foreach ($this->getBatchRecords() as $record) {
      $id = is_array($record) && is_numeric($record['id'] ?? NULL) ? (int) $record['id'] : 0;
      if ($id > 0) {
        $ids[] = $id;
      }
    }
    // An empty id list would reactivate every parked row.
    if ($ids === []) {
      return;
    }
    Herald::reactivateParkedAlerts($this->getCheckPermissions())->setIds($ids)->execute();
    $result->exchangeArray(array_map(static fn (int $id) => ['id' => $id], $ids));
  }

}
