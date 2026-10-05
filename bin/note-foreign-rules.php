<?php

declare(strict_types = 1);

/**
 * Deploy step, run last, after bin/rules-reenable.php: notes broken handlers
 * of other active rules on stderr. Only a class that fatals on load fails it.
 */

$checked = \Civi\Herald\Preflight\DeployGate::noteOthers(STDERR);
echo "[herald preflight] noted: {$checked} handler(s) of other active rules checked\n";
