<?php

/**
 * PHPUnit bootstrap for herald.
 *
 * Boots only the CiviCRM CLASSLOADER — not a full Civi boot. Under
 * CIVICRM_UF=UnitTests the Civi\Test framework then bootstraps headless
 * against TEST_DB_DSN (the isolated civicrm_test scratch DB that civikitchen
 * configures in ~/.cv.json). A `php:boot --level=full` here would bootstrap
 * against the LIVE database first, and the headless run would write its test
 * fixtures and settings into the dev site.
 */

ini_set('memory_limit', '2G');
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli') {
  echo "phpunit must be run from the CLI.\n";
  exit(1);
}

if (!function_exists('cv')) {

  /**
   * Call the "cv" command. Mirrors the helper bundled by civicrm-buildkit /
   * civikitchen — kept inline so the extension is portable.
   */
  function cv(string $cmd, string $decode = 'json') {
    $cmd = "cv $cmd";
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR];
    $oldOutput = getenv('CV_OUTPUT');
    putenv('CV_OUTPUT=json-strict');
    $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__);
    putenv('CV_OUTPUT' . ($oldOutput === FALSE ? '' : '=' . $oldOutput));
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
      throw new RuntimeException('cv command failed: ' . $cmd);
    }
    if ($decode === 'raw' || $decode === 'phpcode') {
      return $result;
    }
    return json_decode($result, TRUE);
  }

}

// On Standalone, SettingsManager::bootSettings() composes CIVICRM_DSN from
// the CIVICRM_DB_* environment variables BEFORE the settings file can apply
// TEST_DB_DSN — env wins and the suite would silently run against the LIVE
// database. Strip them so the UnitTests branch in civicrm.settings.php picks
// the isolated test DSN.
foreach (['CIVICRM_DB_HOST', 'CIVICRM_DB_PORT', 'CIVICRM_DB_NAME', 'CIVICRM_DB_USER', 'CIVICRM_DB_PASSWORD'] as $var) {
  putenv($var);
  unset($_ENV[$var], $_SERVER[$var]);
}

// --test boots with CIVICRM_UF=UnitTests and CIVICRM_DSN=TEST_DB_DSN (the
// isolated civicrm_test scratch DB from ~/.cv.json). Level "settings" defines
// the constants WITHOUT a full Civi boot — Civi\Test\CiviTestListener then
// installs/boots headless on demand.
// phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged -- cv emits PHP code by design; this is the standard civix/buildkit boot pattern.
eval(cv('php:boot --level=settings --test', 'phpcode'));

// The settings boot registers core classes only. The extension's own
// CRM_/Civi\Herald\ classes come from composer's autoload (psr-0/psr-4 in
// composer.json); the dev-deps (Guzzle MockHandler etc.) ride along.
require_once __DIR__ . '/../../vendor/autoload.php';

// CiviRules' base classes (parents of our actions/conditions) must be
// loadable BEFORE the first headless test boots Civi — the pure (@group unit)
// CRM_ tests run first and no extension classloader is active yet. In the
// tests/docker stack CiviRules sits next to herald in the ext dir; elsewhere
// point CIVIRULES_PATH at a checkout.
$civirulesDir = getenv('CIVIRULES_PATH') ?: dirname(__DIR__, 3) . '/org.civicoop.civirules';
if (is_dir($civirulesDir)) {
  $civirulesLoader = new \Composer\Autoload\ClassLoader();
  $civirulesLoader->add('CRM_Civirules', [$civirulesDir]);
  $civirulesLoader->add('CRM_CivirulesActions', [$civirulesDir]);
  $civirulesLoader->add('CRM_CivirulesConditions', [$civirulesDir]);
  // Civi\Api4\CiviRules* etc. — needed while the headless installer enables
  // CiviRules, before the extension classloader is registered.
  $civirulesLoader->addPsr4('Civi\\', [$civirulesDir . '/Civi']);
  $civirulesLoader->register();
}
