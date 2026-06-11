<?php

use Civi\CivicoNotify\StatusTransitionDispatcher;

/**
 * CiviRules condition: did the contribution's status transition from a specific
 * previous value? Reads the pre-edit snapshot captured by civico_notify's
 * hook_civicrm_pre, so this is reliable for "Pending → Completed" detection
 * without depending on CiviRules' optional change-tracking conditions.
 *
 * Action parameter:
 *   previous_status_id  — int, contribution_status_id we expect the row to have
 *                         been holding before the current edit.
 */
class CRM_CivicoNotify_CivirulesConditions_PreviousContributionStatus extends CRM_Civirules_Condition {

  private $conditionParams = [];

  public function setRuleConditionData($ruleCondition) {
    parent::setRuleConditionData($ruleCondition);
    $this->conditionParams = [];
    if (!empty($this->ruleCondition['condition_params'])) {
      $decoded = unserialize($this->ruleCondition['condition_params']);
      if (is_array($decoded)) {
        $this->conditionParams = $decoded;
      }
    }
  }

  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $contribution = $triggerData->getEntityData('Contribution');
    if (empty($contribution['id'])) {
      return FALSE;
    }
    $expected = isset($this->conditionParams['previous_status_id'])
      ? (int) $this->conditionParams['previous_status_id']
      : NULL;
    $previous = StatusTransitionDispatcher::getPreEditStatus((int) $contribution['id']);
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
      'civicrm/civicoNotify/form/civirulescondition/previouscontributionstatus',
      'reset=1&action=update&id=' . $ruleConditionId
    );
  }

  public function userFriendlyConditionParams() {
    $expected = $this->conditionParams['previous_status_id'] ?? NULL;
    if ($expected === NULL) {
      return '(no previous status configured)';
    }
    return sprintf('previous status = #%d', (int) $expected);
  }

  public function requiredEntities() {
    return ['Contribution'];
  }

}
