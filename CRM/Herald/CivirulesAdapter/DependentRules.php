<?php

declare(strict_types = 1);

use Civi\Api4\CiviRulesRule;
use Civi\Herald\Preflight\RuleHandlers;

/**
 * Uninstall deletes herald's handlers, and CiviRules cascades that to the rules'
 * condition rows: a rule would fire without its herald condition. Switch them off first.
 */
final class CRM_Herald_CivirulesAdapter_DependentRules {

  /**
   * Switches off every active rule that uses a herald trigger, condition or action.
   *
   * @return list<int> the rules switched off
   */
  public static function disable(): array {
    if (!CRM_Herald_CivirulesAdapter_Availability::isInstalled()) {
      return [];
    }
    $ids = [];
    foreach (RuleHandlers::active() as $handler) {
      if (!str_starts_with($handler['class'], 'CRM_Herald_')) {
        continue;
      }
      $ids = [...$ids, ...$handler['rule_ids']];
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
