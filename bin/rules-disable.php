<?php

/**
 * Deploy step (core-only, version-independent) — run via `cv scr` BEFORE the
 * code swap. Disables every ACTIVE CiviRules rule backed by a herald
 * condition/action class and remembers their ids, so no web request can
 * evaluate a half-deployed or broken herald class while the extension is being
 * replaced. Paired with bin/rules-reenable.php after a green preflight.
 *
 * The remembered-ids marker is a container file (not a Civi setting): this
 * script runs against the OUTGOING extension, whose settings metadata need not
 * yet know the marker, and a plain file has no such dependency.
 */

const HERALD_DEPLOY_MARKER = '/tmp/herald_deploy_disabled_rules';

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

if ($ids) {
  CRM_Core_DAO::executeQuery('UPDATE civirule_rule SET is_active = 0 WHERE id IN (' . implode(',', $ids) . ')');
}

// Merge with any ids from a previous deploy that failed its preflight (those
// rules are still disabled and must not be forgotten).
$existing = is_file(HERALD_DEPLOY_MARKER)
  ? array_filter(array_map('intval', explode(',', trim((string) file_get_contents(HERALD_DEPLOY_MARKER)))))
  : [];
$all = array_values(array_unique(array_merge($existing, $ids)));
file_put_contents(HERALD_DEPLOY_MARKER, implode(',', $all));

printf(
  "[herald deploy] disabled %d herald rule(s) for the swap: %s (marker now holds %d)\n",
  count($ids),
  implode(',', $ids),
  count($all),
);
