<?php

declare(strict_types = 1);

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Herald\Api4\Rows;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Uninstall deletes herald's handlers, and CiviRules cascades that to the rules'
 * condition rows: a rule would fire without its herald condition. Switch them off first.
 */
final class CRM_Herald_CivirulesAdapter_DependentRules {

  /**
   * Switches off every active rule that uses a herald trigger, condition or
   * action, also when its trigger is off now: the trigger can come back on.
   *
   * @return list<int> the rules switched off
   */
  public static function disable(): array {
    if (!CRM_Herald_CivirulesAdapter_Availability::isInstalled()) {
      return [];
    }
    $rows = [
      ...CiviRulesRule::get(checkPermissions: FALSE)
        ->addSelect('id', 'trigger_id.class_name')
        ->addWhere('is_active', '=', value: TRUE)
        ->execute(),
      ...CiviRulesRuleCondition::get(checkPermissions: FALSE)
        ->addSelect('rule_id', 'condition_id.class_name')
        ->addWhere('rule_id.is_active', '=', value: TRUE)
        ->execute(),
      ...CiviRulesRuleAction::get(checkPermissions: FALSE)
        ->addSelect('rule_id', 'action_id.class_name')
        ->addWhere('rule_id.is_active', '=', value: TRUE)
        ->execute(),
    ];
    $ids = [];
    foreach ($rows as $row) {
      $row = (array) $row;
      $class = Cast::toString(
        $row['trigger_id.class_name'] ?? $row['condition_id.class_name'] ?? $row['action_id.class_name'] ?? '',
      );
      if (str_starts_with($class, 'CRM_Herald_')) {
        $ids[] = Cast::toInt($row['rule_id'] ?? $row['id'] ?? 0);
      }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    if ($ids !== []) {
      CiviRulesRule::update(checkPermissions: FALSE)
        ->addWhere('id', 'IN', $ids)
        ->addValue('is_active', FALSE)
        ->execute();
    }
    return $ids;
  }

  /**
   * Deletes every rule that runs on a herald trigger: CiviRules keeps a trigger
   * while a rule points at it, and no such rule works once the trigger is gone.
   *
   * @return array<int, string> the deleted rules' names by ID
   */
  public static function deleteTriggered(): array {
    if (!CRM_Herald_CivirulesAdapter_Availability::isInstalled()) {
      return [];
    }
    $rules = CiviRulesRule::get(checkPermissions: FALSE)
      ->addSelect('id', 'name')
      ->addWhere('trigger_id.class_name', 'LIKE', 'CRM\_Herald\_%')
      ->addOrderBy('id')
      ->execute();
    $names = [];
    foreach (Rows::of($rules) as $rule) {
      $names[Cast::toInt($rule['id'])] = Cast::toString($rule['name']);
    }
    if ($names !== []) {
      CiviRulesRule::delete(checkPermissions: FALSE)
        ->addWhere('id', 'IN', array_keys($names))
        ->execute();
    }
    return $names;
  }

}
