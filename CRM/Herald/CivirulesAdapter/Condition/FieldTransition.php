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
    $row = $triggerData->getEntityData($entity);
    $rawId = is_array($row) ? $row['id'] ?? 0 : 0;
    $original = CRM_Herald_CivirulesAdapter_OriginalData::rawField($triggerData, $entity, $field);

    // CiviRules evaluates rules in a system context (cron, post-commit
    // queue) — there is no acting user whose ACLs could apply.
    // phpcs:ignore CiviKitchen.Security.PermissionBypass -- system context, see above
    $result = civicrm_api4('Herald', 'checkFieldTransition', [
      'checkPermissions' => FALSE,
      'entity' => $entity,
      'entityId' => is_scalar($rawId) ? (int) $rawId : 0,
      'field' => $field,
      'fromOperator' => self::toStr($params['from_operator'] ?? ''),
      'fromValue' => self::toStr($params['from_value'] ?? ''),
      'toOperator' => self::toStr($params['to_operator'] ?? ''),
      'toValue' => self::toStr($params['to_value'] ?? ''),
      'originalValue' => $original['value'],
      'hasOriginal' => $original['known'],
    ]);

    return (bool) ($result->first()['matched'] ?? FALSE);
  }

  public function getExtraDataInputUrl($ruleConditionId) {
    return CRM_Utils_System::url(
      'civicrm/herald/form/civirulescondition/fieldtransition',
      'reset=1&action=update&id=' . $ruleConditionId,
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
   * never fills $this->conditionParams (see PreviousContributionStatus).
   *
   * @return array<array-key, mixed>
   */
  private function conditionParams(): array {
    $raw = $this->ruleCondition['condition_params'] ?? NULL;
    $decoded = is_string($raw) ? (unserialize($raw, ['allowed_classes' => FALSE]) ?: []) : (array) $raw;
    return is_array($decoded) ? $decoded : [];
  }

  private static function toStr(mixed $value): string {
    return is_scalar($value) ? trim((string) $value) : '';
  }

}
