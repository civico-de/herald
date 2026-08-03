<?php

use Civi\Herald\PreEditSnapshot;

/**
 * CiviRules condition (Event triggers): did this edit flip is_active 1 → 0?
 *
 * Pair with the "Event is changed" trigger to notify on an event being
 * unpublished (a sync setting is_active = FALSE, an admin unchecking "active").
 *
 * Robust against the two ways a naive `is_active = 0` field comparison
 * misfires:
 *   - the trigger's own entity payload can omit is_active on a partial edit,
 *     read as NULL and `null == 0` matches — so the CURRENT value is read from
 *     the live row, not the payload;
 *   - an unrelated edit while the event is already inactive would match a
 *     "currently 0" test — so we require the PRE-edit value to have been 1,
 *     taken from herald's hook_civicrm_pre snapshot (with CiviRules'
 *     original-data as a fallback for delayed execution).
 *
 * No condition parameters.
 */
class CRM_Herald_CivirulesConditions_EventBecameInactive extends CRM_Civirules_Condition {

  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $event = $triggerData->getEntityData('Event');
    $rawId = is_array($event) ? $event['id'] ?? 0 : 0;
    $eventId = is_scalar($rawId) ? (int) $rawId : 0;
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

    return self::wasActive($eventId, $triggerData);
  }

  /**
   * Was the event active before this edit? Snapshot first (reliable in-request),
   * then CiviRules' serialised original data (survives delayed execution).
   */
  private static function wasActive(int $eventId, CRM_Civirules_TriggerData_TriggerData $triggerData): bool {
    $previous = PreEditSnapshot::get('Event', $eventId, 'is_active');
    if ($previous !== NULL) {
      // is_active is a boolean column; a non-scalar snapshot is not a "was
      // active" answer, so it falls through to "unknown" like a missing one.
      return is_scalar($previous) && (int) $previous === 1;
    }
    if (
      $triggerData instanceof CRM_Civirules_TriggerData_Interface_OriginalData
      && strtolower((string) $triggerData->getOriginalEntity()) === 'event'
    ) {
      $original = $triggerData->getOriginalData();
      if (isset($original['is_active']) && is_scalar($original['is_active'])) {
        return (int) $original['is_active'] === 1;
      }
    }
    // Unknown previous state → do not fire (a missed alert beats a false one).
    return FALSE;
  }

  /**
   * No extra-data form — this condition takes no parameters.
   */
  public function getExtraDataInputUrl($ruleConditionId) {
    return FALSE;
  }

  public function userFriendlyConditionParams() {
    return 'Event is_active changed 1 → 0 (deactivated)';
  }

  /**
   * @return string[]
   */
  public function requiredEntities(): array {
    return ['Event'];
  }

}
