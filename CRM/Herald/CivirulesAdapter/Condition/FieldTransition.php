<?php

declare(strict_types = 1);

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_Civirules_Condition')) {
  /**
   * CiviRules adapter: did field X of the configured entity transition from
   * (operator, value) to (operator, value)? The decision is
   * Herald.checkFieldTransition; this unpacks the entity id, the configured
   * comparison and the edit's values before and after.
   *
   * Condition parameters (all strings, via the parameter form):
   *   entity         — APIv4 entity name (e.g. "Contribution")
   *   field          — APIv4 field name on that entity
   *   from_operator / from_value — pre-edit side; empty operator = unconstrained
   *   to_operator / to_value     — post-edit side; empty operator = unconstrained
   */
  class CRM_Herald_CivirulesAdapter_Condition_FieldTransition extends CRM_Civirules_Condition {

    public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
      $params = $this->conditionParams();
      $entity = $params['entity'];
      $field = $params['field'];
      if ($entity === '' || $field === '') {
        return FALSE;
      }
      $rawId = $triggerData->getEntityData($entity)['id'] ?? 0;
      $original = CRM_Herald_CivirulesAdapter_EditData::before($triggerData, $entity, $field);
      $after = CRM_Herald_CivirulesAdapter_EditData::after($triggerData, $entity, $field);

      // CiviRules evaluates rules in a system context (cron, post-commit
      // queue) — there is no acting user whose ACLs could apply.
      $result = \Civi\Api4\Herald::checkFieldTransition(checkPermissions: FALSE)
        ->setEntity($entity)
        ->setEntityId(is_scalar($rawId) ? (int) $rawId : 0)
        ->setField($field)
        ->setFromOperator($params['from_operator'])
        ->setFromValue($params['from_value'])
        ->setToOperator($params['to_operator'])
        ->setToValue($params['to_value'])
        ->setBefore($original['known'] ? ['value' => $original['value']] : [])
        ->setAfter($after['known'] ? ['value' => $after['value']] : [])
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
      $entity = $params['entity'];
      $field = $params['field'];
      if ($entity === '' || $field === '') {
        return '(not configured)';
      }
      $side = static fn (string $op, string $value): string => $op === '' ? '*' : trim($op . ' ' . $value);
      return sprintf(
        '%s.%s: %s → %s',
        $entity,
        $field,
        $side($params['from_operator'], $params['from_value']),
        $side($params['to_operator'], $params['to_value']),
      );
    }

    /**
     * @return string[]
     */
    public function requiredEntities(): array {
      $entity = $this->conditionParams()['entity'];
      return $entity !== '' ? [$entity] : [];
    }

    /**
     * Decode condition_params ourselves — CRM_Civirules_Condition's base class
     * never fills $this->conditionParams.
     *
     * @return array{entity: string, field: string, from_operator: string, from_value: string, to_operator: string, to_value: string}
     */
    private function conditionParams(): array {
      return \Civi\Herald\Condition\FieldTransitionParams::decode($this->ruleCondition['condition_params'] ?? NULL);
    }

  }
}
