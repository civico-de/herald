<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Buffer\DeadLetter;

/**
 * Put parked alerts back into circulation with a fresh retry budget. Nothing
 * un-parks or deletes an item on its own.
 *
 * Example:
 *   cv api4 Herald.reactivateParkedAlerts '{"ids": [12, 13]}'
 */
class ReactivateParkedAlerts extends AbstractAction {

  /**
   * Buffer row ids to reactivate. Empty = every parked row.
   * @var array
   * @phpstan-var array<int, int>
   */
  protected array $ids = [];

  public function _run(Result $result): void {
    $count = (new DeadLetter())->reactivate(array_values(array_map('intval', $this->ids)));
    $result->exchangeArray([['reactivated' => $count]]);
  }

}
