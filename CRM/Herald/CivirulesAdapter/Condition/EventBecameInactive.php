<?php

declare(strict_types = 1);

/**
 * CiviRules adapter (Event triggers): did this edit flip is_active 1 → 0?
 *
 * Pair with the "Event is changed" trigger to notify on an event being
 * unpublished. The decision itself is Herald.checkEventDeactivated; this only
 * unpacks the event id and CiviRules' pre-edit is_active.
 *
 * No condition parameters.
 */
class CRM_Herald_CivirulesAdapter_Condition_EventBecameInactive extends CRM_Civirules_Condition {

  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $event = $triggerData->getEntityData('Event');
    $rawId = is_array($event) ? $event['id'] ?? 0 : 0;

    // CiviRules evaluates rules in a system context (cron, post-commit
    // queue) — there is no acting user whose ACLs could apply.
    $result = \Civi\Api4\Herald::checkEventDeactivated(checkPermissions: FALSE)
      ->setEventId(is_scalar($rawId) ? (int) $rawId : 0)
      ->setOriginalIsActive(CRM_Herald_CivirulesAdapter_OriginalData::intField(
        $triggerData,
        'Event',
        'is_active',
        -1,
      ))
      ->execute();

    return (bool) ($result->first()['matched'] ?? FALSE);
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
