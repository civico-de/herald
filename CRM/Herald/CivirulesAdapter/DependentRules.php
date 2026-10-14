<?php

declare(strict_types = 1);

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
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

}
