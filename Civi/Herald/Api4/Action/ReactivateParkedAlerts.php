<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Buffer\DeadLetter;

/**
 * Put parked buffered alerts back into circulation once the fault behind them
 * is fixed — attempt counters reset, so they get a full retry budget again.
 *
 * Deliberate by design: nothing un-parks an item on its own, and nothing
 * deletes it either.
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
