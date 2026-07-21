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

// CRITICAL GUARD: the headless suite must run against a SEPARATE scratch
// database. Without this, a missing TEST_DB_DSN lets civicrm.settings.php fall
// back to the MAIN dev DB and Civi\Test wipes all dev data.
// Parsed, not grepped: the old form looked for the substrings '"TEST_DB_DSN"'
// and 'civicrm_test' anywhere in the raw file. Two unrelated matches satisfied
// it — 'civicrm_test' out of a directory *path*, or a TEST_DB_DSN belonging to
// a different site in a multi-site config — while the site actually booted had
// none. Decode the JSON and check the database NAME the DSN points at.
$ckTestDsns = [];
$ckHome = getenv('HOME') ?: '';
$ckRaw = $ckHome !== '' ? (string) @file_get_contents($ckHome . '/.cv.json') : '';
$ckConfig = $ckRaw !== '' ? json_decode($ckRaw, TRUE) : NULL;
foreach ((array) ($ckConfig['sites'] ?? []) as $ckSite) {
  $ckDsn = is_array($ckSite) ? ($ckSite['TEST_DB_DSN'] ?? NULL) : NULL;
  if (is_string($ckDsn) && $ckDsn !== '') {
    $ckTestDsns[] = $ckDsn;
  }
}
// Every DSN present must name a scratch database, and there must be one. A
// site whose TEST_DB_DSN points at the main DB is the exact accident this
// guard exists to prevent.
$ckBadDsn = NULL;
foreach ($ckTestDsns as $ckDsn) {
  $ckDb = explode('?', ltrim((string) (parse_url($ckDsn, PHP_URL_PATH) ?: ''), '/'))[0];
  if (!str_ends_with($ckDb, '_test')) {
    $ckBadDsn = $ckDsn;
    break;
  }
}
if ($ckTestDsns === [] || $ckBadDsn !== NULL) {
  fwrite(STDERR, $ckBadDsn !== NULL
    ? "ABORT: TEST_DB_DSN does not name a *_test database ({$ckBadDsn}) — headless tests would rebuild it.\n"
    : "ABORT: no TEST_DB_DSN in \$HOME/.cv.json — headless tests would rebuild the MAIN dev DB.\n"
      . "Re-provision the stack (`docker compose down -v && up -d`) — the civikitchen\n"
      . "entrypoint writes TEST_DB_DSN and seeds the civicrm_test scratch DB on first boot.\n");
  exit(1);
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
