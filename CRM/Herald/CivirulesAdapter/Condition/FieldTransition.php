<?php

declare(strict_types = 1);

/**
 * CiviRules adapter: did field X of the configured entity transition from
 * (operator, value) to (operator, value)? The decision is
 * Herald.checkFieldTransition; this unpacks the entity id, the configured
 * comparison and CiviRules' pre-edit value.
 *
 * Condition parameters (all strings, via the parameter form):
 *   entity         — APIv4 entity name (e.g. "Contribution")
 *   field          — APIv4 field name on that entity
 *   from_operator / from_value — pre-edit side; empty operator = unconstrained
 *   to_operator / to_value     — post-edit side; empty operator = unconstrained
 * @mago-expect lint:cyclomatic-complexity
 */
class CRM_Herald_CivirulesAdapter_Condition_FieldTransition extends CRM_Civirules_Condition {

  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $params = $this->conditionParams();
    $entity = self::toStr($params['entity'] ?? '');
    $field = self::toStr($params['field'] ?? '');
    if ($entity === '' || $field === '') {
      return FALSE;
    }
    $rawId = $triggerData->getEntityData($entity)['id'] ?? 0;
    $original = CRM_Herald_CivirulesAdapter_OriginalData::rawField($triggerData, $entity, $field);

    // CiviRules evaluates rules in a system context (cron, post-commit
    // queue) — there is no acting user whose ACLs could apply.
    $result = \Civi\Api4\Herald::checkFieldTransition(checkPermissions: FALSE)
      ->setEntity($entity)
      ->setEntityId(is_scalar($rawId) ? (int) $rawId : 0)
      ->setField($field)
      ->setFromOperator(self::toStr($params['from_operator'] ?? ''))
      ->setFromValue(self::toStr($params['from_value'] ?? ''))
      ->setToOperator(self::toStr($params['to_operator'] ?? ''))
      ->setToValue(self::toStr($params['to_value'] ?? ''))
      ->setOriginalValue($original['value'])
      ->setHasOriginal($original['known'])
      ->execute();

    return !empty($result->first()['matched']);
  }

  /**
   * CiviRules' base form reads rule_condition_id; without it, find() loads the
   * first rule condition of any rule.
   */
  public function getExtraDataInputUrl($ruleConditionId) {
    return $this->getFormattedExtraDataInputUrl(
      'civicrm/herald/form/civirulescondition/fieldtransition',
      (int) $ruleConditionId,
    );
  }

  public function userFriendlyConditionParams() {
    $params = $this->conditionParams();
    $entity = self::toStr($params['entity'] ?? '');
    $field = self::toStr($params['field'] ?? '');
    if ($entity === '' || $field === '') {
      return '(not configured)';
    }
    $side = static fn (string $op, string $value): string => $op === '' ? '*' : trim($op . ' ' . $value);
    return sprintf(
      '%s.%s: %s → %s',
      $entity,
      $field,
      $side(self::toStr($params['from_operator'] ?? ''), self::toStr($params['from_value'] ?? '')),
      $side(self::toStr($params['to_operator'] ?? ''), self::toStr($params['to_value'] ?? '')),
    );
  }

  /**
   * @return string[]
   */
  public function requiredEntities(): array {
    $entity = self::toStr($this->conditionParams()['entity'] ?? '');
    return $entity !== '' ? [$entity] : [];
  }

  /**
   * Decode condition_params ourselves — CRM_Civirules_Condition's base class
   * never fills $this->conditionParams.
   *
   * @return array<array-key, mixed>
   */
  private function conditionParams(): array {
    $raw = $this->ruleCondition['condition_params'] ?? NULL;
    $decoded = is_string($raw) ? unserialize($raw, ['allowed_classes' => FALSE]) : $raw;
    return is_array($decoded) ? $decoded : [];
  }

  private static function toStr(mixed $value): string {
    return is_scalar($value) ? trim((string) $value) : '';
  }

}
