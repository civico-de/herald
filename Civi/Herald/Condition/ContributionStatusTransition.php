<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Herald\StatusTransitionDispatcher;

/**
 * Did a contribution transition FROM a specific status?
 *
 * Reads the pre-edit snapshot captured by herald's hook_civicrm_pre (with the
 * caller's own original-data value taking precedence), so "Pending →
 * Completed" detection does not depend on optional change-tracking conditions.
 */
final class ContributionStatusTransition {

  /**
   * @param int $contributionId Contribution the edit touched.
   * @param int|null $expectedStatusId Configured previous status; NULL = not configured.
   * @param int|null $originalStatusId contribution_status_id from the caller's
   *   own pre-edit record, NULL when unavailable.
   */
  public static function transitionedFrom(
    int $contributionId,
    ?int $expectedStatusId,
    ?int $originalStatusId = NULL,
  ): bool {
    if ($contributionId <= 0) {
      return FALSE;
    }
    return self::matches(
      $expectedStatusId,
      StatusTransitionDispatcher::resolvePreEditStatus($contributionId, $originalStatusId),
    );
  }

  /**
   * Pure match check: TRUE iff the expected previous status is configured AND
   * matches the snapshot. NULL on either side means "no match".
   */
  public static function matches(?int $expected, ?int $previous): bool {
    if ($expected === NULL) {
      return FALSE;
    }
    return $previous === $expected;
  }

}
