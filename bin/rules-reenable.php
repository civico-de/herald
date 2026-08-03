<?php

declare(strict_types = 1);

/**
 * Deploy step (core-only) — run via `cv scr` ONLY after bin/preflight-rules.php
 * passed. Re-enables exactly the rules that bin/rules-disable.php switched off
 * for the swap, and clears the marker file. If the preflight failed, deploy.sh
 * never reaches this step and the rules stay disabled (fail-safe).
 */

const HERALD_DEPLOY_MARKER = '/tmp/herald_deploy_disabled_rules';

$ids = is_file(HERALD_DEPLOY_MARKER)
  ? array_values(array_filter(array_map('intval', explode(
    ',',
    trim((string) file_get_contents(HERALD_DEPLOY_MARKER)),
  ))))
  : [];

if ($ids) {
  CRM_Core_DAO::executeQuery('UPDATE civirule_rule SET is_active = 1 WHERE id IN (' . implode(',', $ids) . ')');
}
if (is_file(HERALD_DEPLOY_MARKER)) {
  unlink(HERALD_DEPLOY_MARKER);
}
echo '[herald deploy] re-enabled ' . count($ids) . ' herald rule(s): ' . implode(',', $ids) . "\n";
