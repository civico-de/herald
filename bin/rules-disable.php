<?php

declare(strict_types = 1);

/**
 * Deploy step, run via `cv scr` before the code swap: records, then disables,
 * the active rules backed by a herald condition, action or trigger class for the gate.
 */

// Spelled out, like DeployGate::marker() below: this runs before the swap, against the old classes.
if (CRM_Extension_System::singleton()->getManager()->getStatus('org.civicoop.civirules') !== 'installed') {
  echo "[herald deploy] CiviRules is not installed; no rule to disable\n";
  return;
}

$marker = Civi::paths()->getPath('[civicrm.private]/herald_deploy_disabled_rules');
if (!is_string($marker)) {
  throw new RuntimeException('[herald deploy] cannot resolve the marker path; no rule was disabled');
}

$rows = [
  ...\Civi\Api4\CiviRulesRule::get(checkPermissions: FALSE)
    ->addSelect('id')
    ->addWhere('is_active', '=', value: TRUE)
    ->addWhere('trigger_id.class_name', 'LIKE', 'CRM_Herald%')
    ->execute(),
  ...\Civi\Api4\CiviRulesRuleCondition::get(checkPermissions: FALSE)
    ->addSelect('rule_id')
    ->addWhere('rule_id.is_active', '=', value: TRUE)
    ->addWhere('condition_id.class_name', 'LIKE', 'CRM_Herald%')
    ->execute(),
  ...\Civi\Api4\CiviRulesRuleAction::get(checkPermissions: FALSE)
    ->addSelect('rule_id')
    ->addWhere('rule_id.is_active', '=', value: TRUE)
    ->addWhere('action_id.class_name', 'LIKE', 'CRM_Herald%')
    ->execute(),
];
$ids = [];
// Plain PHP: the swap has not happened, so herald's newer helpers may be missing.
foreach ($rows as $row) {
  $id = is_array($row) ? $row['rule_id'] ?? $row['id'] ?? NULL : NULL;
  $ids[] = is_numeric($id) ? (int) $id : 0;
}
$ids = array_filter($ids);
$ids = array_values(array_unique($ids));
sort($ids);

// Keeps the ids of a previous deploy that failed its gate: those rules are still disabled.
$existing = is_file($marker)
  ? array_filter(array_map('intval', explode(',', trim((string) file_get_contents($marker)))))
  : [];
$all = array_values(array_unique(array_merge($existing, $ids)));
if (file_put_contents($marker, implode(',', $all)) === FALSE) {
  throw new RuntimeException("[herald deploy] cannot write {$marker}; no rule was disabled");
}

if ($ids !== []) {
  // ckconform-ignore raw-sql -- an APIv4 update marks a managed rule as modified, and its extension stops updating it
  CRM_Core_DAO::executeQuery('UPDATE civirule_rule SET is_active = 0 WHERE id IN (%1)', [1 => [
    implode(',', $ids),
    'CommaSeparatedIntegers',
  ]]);
}

printf(
  "[herald deploy] disabled %d herald rule(s) for the swap: %s (marker now holds %d)\n",
  count($ids),
  implode(',', $ids),
  count($all),
);
