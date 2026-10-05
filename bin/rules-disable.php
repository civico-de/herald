<?php

declare(strict_types = 1);

/**
 * Deploy step, run via `cv scr` before the code swap: records, then disables,
 * the active rules backed by a herald condition or action class for the gate.
 */

// DeployGate::marker(), spelled out: this runs before the swap, against the old classes.
$marker = Civi::paths()->getPath('[civicrm.private]/herald_deploy_disabled_rules');

$ids = [];
$dao = CRM_Core_DAO::executeQuery(
  "SELECT DISTINCT r.id
   FROM civirule_rule r
   LEFT JOIN civirule_rule_action ra ON ra.rule_id = r.id
   LEFT JOIN civirule_action a ON a.id = ra.action_id
   LEFT JOIN civirule_rule_condition rc ON rc.rule_id = r.id
   LEFT JOIN civirule_condition c ON c.id = rc.condition_id
   WHERE r.is_active = 1
     AND (a.class_name LIKE 'CRM_Herald%' OR c.class_name LIKE 'CRM_Herald%')",
);
while ($dao->fetch()) {
  $ids[] = (int) $dao->id;
}

// Keeps the ids of a previous deploy that failed its gate: those rules are still disabled.
$existing = is_file($marker)
  ? array_filter(array_map('intval', explode(',', trim((string) file_get_contents($marker)))))
  : [];
$all = array_values(array_unique(array_merge($existing, $ids)));
if (file_put_contents($marker, implode(',', $all)) === FALSE) {
  throw new RuntimeException("[herald deploy] cannot write {$marker}; no rule was disabled");
}

if ($ids !== []) {
  CRM_Core_DAO::executeQuery('UPDATE civirule_rule SET is_active = 0 WHERE id IN (' . implode(',', $ids) . ')');
}

printf(
  "[herald deploy] disabled %d herald rule(s) for the swap: %s (marker now holds %d)\n",
  count($ids),
  implode(',', $ids),
  count($all),
);
