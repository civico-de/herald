<?php

declare(strict_types = 1);

/**
 * Deploy preflight — run via `cv scr /var/www/html/ext/herald/bin/preflight-rules.php`
 * AFTER deploying the new code and BEFORE re-enabling herald's CiviRules rules
 * (see deploy.sh). Verifies every handler class herald registers actually loads
 * and is instantiable, and caches the result for the pulse health check.
 *
 * A broken class throws here → `cv scr` exits non-zero → deploy.sh (set -e)
 * aborts while the rules are still disabled, so a class that would 500 the
 * write path never goes live. (A non-catchable load-fatal aborts even harder,
 * with the offending class named in stderr — same safe outcome.)
 */

use Civi\Herald\RuleHealth;

$broken = RuleHealth::refresh();
$count = count(RuleHealth::registeredHandlerClasses());
echo "[herald preflight] checked {$count} handler class(es)\n";

if ($broken === []) {
  echo "[herald preflight] OK — all handler classes loadable and instantiable\n";
  return;
}

foreach ($broken as $class => $reason) {
  fwrite(STDERR, "[herald preflight] BROKEN: {$class} — {$reason}\n");
}
throw new RuntimeException(count($broken) . ' herald handler class(es) not loadable — rules left disabled');
