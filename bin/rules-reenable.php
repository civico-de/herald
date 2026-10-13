<?php

declare(strict_types = 1);

/**
 * Deploy step, run after bin/preflight-rules.php passed: re-enables the
 * rules bin/rules-disable.php switched off and clears the marker.
 */

use Civi\Herald\Preflight\DeployGate;

if (!CRM_Herald_CivirulesAdapter_Availability::isInstalled()) {
  echo "[herald deploy] CiviRules is not installed; no rule to re-enable\n";
  return;
}

$ids = DeployGate::markedRuleIds();
if ($ids !== []) {
  CRM_Core_DAO::executeQuery('UPDATE civirule_rule SET is_active = 1 WHERE id IN (' . implode(',', $ids) . ')');
}
if (is_file(DeployGate::marker())) {
  unlink(DeployGate::marker());
}
echo '[herald deploy] re-enabled ' . count($ids) . ' herald rule(s): ' . implode(',', $ids) . "\n";
