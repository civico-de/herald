<?php

declare(strict_types = 1);

/**
 * CiviRules adapter: did the contribution's status transition from a specific
 * previous value? The decision is Herald.checkContributionTransition; this
 * unpacks the contribution id, the configured status and CiviRules' pre-edit
 * status.
 *
 * Condition parameter:
 *   previous_status_id  — int, contribution_status_id we expect the row to have
 *                         been holding before the current edit.
 */
class CRM_Herald_CivirulesAdapter_Condition_PreviousContributionStatus extends CRM_Civirules_Condition {

  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $contribution = $triggerData->getEntityData('Contribution');
    if (empty($contribution['id'])) {
      return FALSE;
    }

    // CiviRules evaluates rules in a system context (cron, post-commit
    // queue) — there is no acting user whose ACLs could apply.
    $result = \Civi\Api4\Herald::checkContributionTransition(checkPermissions: FALSE)
      ->setContributionId(self::toInt($contribution['id']))
      ->setExpectedStatusId($this->expectedPreviousStatusId() ?? 0)
      ->setOriginalStatusId(CRM_Herald_CivirulesAdapter_OriginalData::intField(
        $triggerData,
        'Contribution',
        'contribution_status_id',
      ))
      ->execute();

    return (bool) ($result->first()['matched'] ?? FALSE);
  }

  /**
   * previous_status_id aus den gespeicherten condition_params.
   *
   * CRM_Civirules_Condition::setRuleConditionData() befüllt nur
   * $this->ruleCondition, nie $this->conditionParams — daher selbst dekodieren.
   */
  private function expectedPreviousStatusId(): ?int {
    $raw = $this->ruleCondition['condition_params'] ?? NULL;
    // allowed_classes=FALSE: condition_params enthält nur Skalare.
    $decoded = is_string($raw) ? (unserialize($raw, ['allowed_classes' => FALSE]) ?: []) : (array) $raw;
    $params = is_array($decoded) ? $decoded : [];
    return isset($params['previous_status_id']) ? self::toInt($params['previous_status_id']) : NULL;
  }

  /**
   * CiviRules' base form reads rule_condition_id; without it, find() loads the
   * first rule condition of any rule.
   */
  public function getExtraDataInputUrl($ruleConditionId) {
    return $this->getFormattedExtraDataInputUrl('civicrm/herald/form/civirulescondition/previouscontributionstatus', (int) $ruleConditionId);
  }

  public function userFriendlyConditionParams() {
    $expected = $this->expectedPreviousStatusId();
    if ($expected === NULL) {
      return '(no previous status configured)';
    }
    return sprintf('previous status = #%d', $expected);
  }

  /**
   * @return string[]
   */
  public function requiredEntities(): array {
    return ['Contribution'];
  }

  /**
   * Narrow a mixed condition-param value to int. Condition params are decoded
   * from serialized storage and this field is always numeric per the form that
   * writes it.
   */
  private static function toInt(mixed $value): int {
    return is_scalar($value) ? (int) $value : 0;
  }

}
