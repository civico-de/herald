<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Condition\ContributionStatusTransition;

/**
 * Did a contribution transition FROM a specific status?
 *
 * Backs the "Previous Contribution status" CiviRules condition.
 */
class CheckContributionTransition extends AbstractAction {

  /**
   * Contribution the edit touched.
   * @var int
   */
  protected int $contributionId = 0;

  /**
   * Configured previous contribution_status_id; 0 = not configured (no match).
   * @var int
   */
  protected int $expectedStatusId = 0;

  /**
   * contribution_status_id from the caller's own pre-edit record; 0 = unknown,
   * in which case herald's own hook_civicrm_pre snapshot is used.
   * @var int
   */
  protected int $originalStatusId = 0;

  public function _run(Result $result): void {
    $result[] = [
      'matched' => ContributionStatusTransition::transitionedFrom(
        $this->contributionId,
        $this->expectedStatusId ?: NULL,
        $this->originalStatusId ?: NULL,
      ),
    ];
  }

}
