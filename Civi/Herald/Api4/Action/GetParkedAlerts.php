<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Buffer\DeadLetter;

/**
 * List the buffered alerts parked in the dead letter: what is stuck, since
 * when, after how many attempts and why.
 *
 * Rendered bodies are deliberately not returned — they can carry contact data,
 * and this view answers the operational question, not the content one.
 *
 * Example:
 *   cv api4 Herald.getParkedAlerts
 */
class GetParkedAlerts extends AbstractAction {

  /**
   * Restrict to these buffer row ids. Empty = every parked row.
   * @var array
   * @phpstan-var array<int, int>
   */
  protected array $ids = [];

  public function _run(Result $result): void {
    $result->exchangeArray((new DeadLetter())->getParked(array_values(array_map('intval', $this->ids))));
  }

}
