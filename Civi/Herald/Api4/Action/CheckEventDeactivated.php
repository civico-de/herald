<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Condition\EventDeactivation;

/**
 * Did this edit flip an Event's is_active from 1 to 0?
 *
 * Backs the "Event was deactivated" CiviRules condition.
 *
 * @method $this setEventId(int $eventId)
 * @method $this setOriginalIsActive(int $originalIsActive)
 */
class CheckEventDeactivated extends AbstractAction {

  /**
   * Event the edit touched.
   * @var int
   */
  protected int $eventId = 0;

  /**
   * is_active from the caller's own pre-edit record: 1, 0, or -1 for "unknown"
   * (herald's own hook_civicrm_pre snapshot is then the only source).
   * @var int
   */
  protected int $originalIsActive = -1;

  public function _run(Result $result): void {
    $result[] = [
      'matched' => EventDeactivation::isDeactivated(
        $this->eventId,
        $this->originalIsActive >= 0 ? $this->originalIsActive : NULL,
      ),
    ];
  }

}
