<?php

use Civi\Herald\StatusTransitionDispatcher;

/**
 * CiviRules condition: did the contribution's status transition from a specific
 * previous value? Reads the pre-edit snapshot captured by herald's
 * hook_civicrm_pre, so this is reliable for "Pending → Completed" detection
 * without depending on CiviRules' optional change-tracking conditions.
 *
 * Action parameter:
 *   previous_status_id  — int, contribution_status_id we expect the row to have
 *                         been holding before the current edit.
 */
class CRM_Herald_CivirulesConditions_PreviousContributionStatus extends CRM_Civirules_Condition {

  // $conditionParams comes from the base class — it decodes condition_params
  // (serialized or array) in setRuleConditionData. No override needed.

  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $contribution = $triggerData->getEntityData('Contribution');
    if (empty($contribution['id'])) {
      return FALSE;
    }
    $expected = isset($this->conditionParams['previous_status_id'])
      ? self::toInt($this->conditionParams['previous_status_id'])
      : NULL;
    $previous = StatusTransitionDispatcher::resolvePreEditStatus(self::toInt($contribution['id']), $triggerData);
    return self::matches($expected, $previous);
  }

  /**
   * Pure match check. Test-seam.
   *
   * Returns TRUE iff the expected previous status is configured AND matches
   * the snapshot. NULL on either side means "no match".
   */
  public static function matches(?int $expected, ?int $previous): bool {
    if ($expected === NULL) {
      return FALSE;
    }
    return $previous === $expected;
  }

  public function getExtraDataInputUrl($ruleConditionId) {
    return CRM_Utils_System::url(
      'civicrm/herald/form/civirulescondition/previouscontributionstatus',
      'reset=1&action=update&id=' . $ruleConditionId
    );
  }

  public function userFriendlyConditionParams() {
    $expected = $this->conditionParams['previous_status_id'] ?? NULL;
    if ($expected === NULL) {
      return '(no previous status configured)';
    }
    return sprintf('previous status = #%d', self::toInt($expected));
  }

  /**
   * @return string[]
   */
  public function requiredEntities(): array {
    return ['Contribution'];
  }

  /**
   * Narrow a mixed condition-param value to int. Condition params are
   * decoded from serialized storage and this field is always numeric per
   * the form that writes it; this guards the cast against the theoretical
   * mixed type without changing behaviour for well-formed data.
   */
  private static function toInt(mixed $value): int {
    return is_scalar($value) ? (int) $value : 0;
  }

}
