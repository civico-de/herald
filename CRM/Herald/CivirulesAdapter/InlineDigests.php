<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * "Send digest" actions whose digest still sits in their parameters get a
 * HeraldDigest of their own (CRM_Herald_Upgrader_InlineDigest).
 */
final class CRM_Herald_CivirulesAdapter_InlineDigests {

  /**
   * @return int Number of rule actions rewritten.
   */
  public static function migrate(): int {
    if (!CRM_Herald_CivirulesAdapter_Availability::isInstalled()) {
      return 0;
    }
    /** @var list<array<string, mixed>> $actions */
    $actions = \Civi\Api4\CiviRulesRuleAction::get(checkPermissions: FALSE)
      ->addSelect('id', 'action_params', 'rule_id.label')
      ->addWhere('action_id.class_name', '=', 'CRM_Herald_CivirulesAdapter_Action_Digest')
      ->execute()
      ->getArrayCopy();
    $migrated = 0;
    foreach ($actions as $action) {
      $params = is_array($action['action_params'] ?? NULL) ? Cast::namedOnly($action['action_params']) : [];
      if (!CRM_Herald_Upgrader_InlineDigest::isInline($params)) {
        continue;
      }
      $id = Cast::toInt($action['id']);
      $title = Cast::toString($action['rule_id.label'] ?? '') ?: "Rule action {$id}";
      $delivery = CRM_Herald_Upgrader_InlineDigest::extract($params, "rule_action_{$id}", $title);
      if ($delivery === NULL) {
        continue;
      }
      \Civi\Api4\CiviRulesRuleAction::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $id)
        ->addValue('action_params', $delivery)
        ->execute();
      $migrated++;
    }
    return $migrated;
  }

}
