<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Herald\PreEditSnapshot;

/**
 * Did this edit flip an Event's is_active from 1 to 0?
 *
 * Robust against the two ways a naive `is_active = 0` field comparison
 * misfires:
 *   - the trigger's own entity payload can omit is_active on a partial edit,
 *     read as NULL and `null == 0` matches — so the CURRENT value is read from
 *     the live row, not the payload;
 *   - an unrelated edit while the event is already inactive would match a
 *     "currently 0" test — so we require the PRE-edit value to have been 1,
 *     taken from herald's hook_civicrm_pre snapshot, with the caller's
 *     original-data value as a fallback for delayed execution.
 */
final class EventDeactivation {

  /**
   * @param int $eventId Event the edit touched.
   * @param int|null $originalIsActive is_active from the caller's own pre-edit
   *   record (CiviRules serialises this into its queue), NULL when unavailable.
   */
  public static function isDeactivated(int $eventId, ?int $originalIsActive = NULL): bool {
    if ($eventId <= 0) {
      return FALSE;
    }

    // CURRENT value from the live row — authoritative, unlike the payload.
    $current = \CRM_Core_DAO::singleValueQuery(
      'SELECT is_active FROM civicrm_event WHERE id = %1',
      [1 => [$eventId, 'Integer']],
    );
    if ($current === NULL || (int) $current !== 0) {
      // Row gone (a delete, handled by its own trigger) or still active.
      return FALSE;
    }

    return self::wasActive($eventId, $originalIsActive);
  }

  /**
   * Was the event active before this edit? Snapshot first (reliable in-request),
   * then the caller's serialised original data (survives delayed execution).
   * Unknown previous state → FALSE (a missed alert beats a false one).
   */
  private static function wasActive(int $eventId, ?int $originalIsActive): bool {
    $previous = PreEditSnapshot::get('Event', $eventId, 'is_active');
    if ($previous !== NULL) {
      // is_active is a boolean column; a non-scalar snapshot is not a "was
      // active" answer, so it falls through to "unknown" like a missing one.
      return is_scalar($previous) && (int) $previous === 1;
    }
    return $originalIsActive === 1;
  }

}
