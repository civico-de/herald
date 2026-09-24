<?php

declare(strict_types = 1);

/**
 * Deploy gate, run by the deployer as a `hooks.post` script before bin/rules-reenable.php;
 * a failure exits non-zero and leaves herald's rules disabled.
 */

try {
  $checked = \Civi\Herald\Preflight\DeployGate::run(STDERR);
}
catch (\CRM_Core_Exception $e) {
  // Written unwrapped: the console's exception box would split the re-enable command.
  fwrite(STDERR, '[herald preflight] FAILED: ' . $e->getMessage() . "\n");
  exit(1);
}
echo "[herald preflight] OK: {$checked} CiviRules handler(s) checked\n";
