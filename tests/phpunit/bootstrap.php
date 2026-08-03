<?php

ini_set('memory_limit', '2G');

// phpcs:disable
eval(cv('php:boot --level=classloader', 'phpcode'));
// phpcs:enable

// Half of the deprecation gate. phpunit.xml.dist has
// convertDeprecationsToExceptions, but PHPUnit's error handler ignores errors
// outside error_reporting() — and the CLI default masks E_DEPRECATED (22527).
// CiviTestListener raises it to E_ALL only for its own tests, so plain unit
// tests would swallow engine deprecations without this line.
error_reporting(E_ALL);

// CRITICAL GUARD: the headless suite must run against a SEPARATE scratch
// database. The DSN comes from cv's config (TEST_DB_DSN in ~/.cv.json —
// provisioned by the civikitchen entrypoint for BOTH /root and /var/www):
// the phpunit listener re-boots via `cv php:boot --level=full`, whose
// $GLOBALS['_CV'] replaces anything set here. If the config is missing,
// civicrm.settings.php silently falls back to the MAIN dev DB and
// Civi\Test wipes all dev data — so fail loudly instead.
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
  $ckDsn = is_array($ckSite) ? $ckSite['TEST_DB_DSN'] ?? NULL : NULL;
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
  fwrite(
    STDERR,
    $ckBadDsn !== NULL
      ? "ABORT: TEST_DB_DSN does not name a *_test database ({$ckBadDsn}) — headless tests would rebuild it.\n"
      : "ABORT: no TEST_DB_DSN in \$HOME/.cv.json — headless tests would rebuild the MAIN dev DB.\n"
      . "Re-provision the stack (`docker compose down -v && up -d`) — the civikitchen\n"
      . "entrypoint writes TEST_DB_DSN and seeds the civicrm_test scratch DB on first boot.\n",
  );
  exit(1);
}

// Standalone quirk: SettingsManager::bootSettings() composes CIVICRM_DSN
// from the CIVICRM_DB_* env vars BEFORE settings.php gets a chance to apply
// TEST_DB_DSN — the env-composed (main!) DSN would win. Repoint the DB name
// at the scratch DB for this whole test process (inherited by the
// listener's `cv php:boot --level=full` subprocess too).
putenv('CIVICRM_DB_NAME=civicrm_test');
$_ENV['CIVICRM_DB_NAME'] = 'civicrm_test';

// Allow autoloading of PHPUnit helper classes in this extension.
$loader = new \Composer\Autoload\ClassLoader();
$loader->add('CRM_', [__DIR__ . '/../..', __DIR__]);
$loader->addPsr4('Civi\\', [__DIR__ . '/../../Civi', __DIR__ . '/Civi']);
$loader->add('api_', [__DIR__ . '/../..', __DIR__]);
$loader->addPsr4('api\\', [__DIR__ . '/../../api', __DIR__ . '/api']);

$loader->register();

/**
 * Call the "cv" command.
 *
 * @param string $cmd
 *   The rest of the command to send.
 * @param string $decode
 *   Ex: 'json' or 'phpcode'.
 * @return mixed
 *   Response output (if the command executed normally).
 *   For 'raw' or 'phpcode', this will be a string. For 'json', it could be any JSON value.
 * @throws \RuntimeException
 *   If the command terminates abnormally.
 */
function cv(string $cmd, string $decode = 'json') {
  $cmd = 'cv ' . $cmd;
  $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR];
  $oldOutput = getenv('CV_OUTPUT');
  putenv('CV_OUTPUT=json');

  // Execute `cv` in the original folder. This is a work-around for
  // phpunit/codeception, which seem to manipulate PWD.
  $cmd = sprintf('cd %s; %s', escapeshellarg(getenv('PWD')), $cmd);

  $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__);
  putenv("CV_OUTPUT=$oldOutput");
  fclose($pipes[0]);
  $result = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  if (proc_close($process) !== 0) {
    throw new RuntimeException("Command failed ($cmd):\n$result");
  }
  switch ($decode) {
    case 'raw':
      return $result;

    case 'phpcode':
      // If the last output is /*PHPCODE*/, then we managed to complete execution.
      if (substr(trim($result), 0, 12) !== '/*BEGINPHP*/' || substr(trim($result), -10) !== '/*ENDPHP*/') {
        throw new \RuntimeException("Command failed ($cmd):\n$result");
      }
      return $result;

    case 'json':
      return json_decode($result, 1);

    default:
      throw new RuntimeException("Bad decoder format ($decode)");
  }
}

// Repo-specific test setup (extra constants, fixture loaders) goes in
// bootstrap.local.php — THIS file is template-managed and rewritten wholesale
// by ckinit --update.
if (file_exists(__DIR__ . '/bootstrap.local.php')) {
  require __DIR__ . '/bootstrap.local.php';
}
