<?php

ini_set('memory_limit', '2G');

eval(cv('php:boot --level=classloader', 'phpcode'));

// PHPUnit ignores errors outside error_reporting(), and the CLI default masks
// E_DEPRECATED, so without this plain unit tests swallow deprecations.
error_reporting(E_ALL);

// Without TEST_DB_DSN in ~/.cv.json, civicrm.settings.php falls back to the main
// dev DB and Civi\Test wipes it. Check the database name of every DSN, not a substring.
$ckTestDsns = [];
$ckHome = getenv('HOME') ?: '';
$ckRaw = $ckHome !== '' ? (string) @file_get_contents($ckHome . '/.cv.json') : '';
$ckConfig = $ckRaw !== '' ? json_decode($ckRaw, TRUE) : NULL;
$ckSites = is_array($ckConfig) && is_array($ckConfig['sites'] ?? NULL) ? $ckConfig['sites'] : [];
foreach ($ckSites as $ckSite) {
  $ckDsn = is_array($ckSite) ? $ckSite['TEST_DB_DSN'] ?? NULL : NULL;
  if (is_string($ckDsn) && $ckDsn !== '') {
    $ckTestDsns[] = $ckDsn;
  }
}
// There must be a DSN, and every one must name a *_test database.
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

// Standalone composes CIVICRM_DSN from CIVICRM_DB_* before settings.php applies
// TEST_DB_DSN; repoint the name for this process and its cv subprocesses.
putenv('CIVICRM_DB_NAME=civicrm_test');
$_ENV['CIVICRM_DB_NAME'] = 'civicrm_test';

// Allow autoloading of PHPUnit helper classes in this extension.
$loader = new \Composer\Autoload\ClassLoader();
$loader->add('CRM_', [__DIR__ . '/../..', __DIR__]);
$loader->addPsr4('Civi\\', [__DIR__ . '/../../Civi', __DIR__ . '/Civi']);
$loader->add('api_', [__DIR__ . '/../..', __DIR__]);
$loader->addPsr4('api\\', [__DIR__ . '/../../api', __DIR__ . '/api']);

$loader->register();

// ck_headless(): the headless builder with info.xml <requires> resolved.
require __DIR__ . '/ckHeadless.php';

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
  $pwd = getenv('PWD');
  $cwd = getcwd();
  $cmd = sprintf('cd %s; %s', escapeshellarg(is_string($pwd) ? $pwd : (is_string($cwd) ? $cwd : __DIR__)), $cmd);

  $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__);
  if (!is_resource($process)) {
    throw new RuntimeException("Could not start command: $cmd");
  }
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
      return json_decode($result, TRUE);

    default:
      throw new RuntimeException("Bad decoder format ($decode)");
  }
}

// Repo-specific setup goes in bootstrap.local.php; ckinit --update rewrites this file.
if (file_exists(__DIR__ . '/bootstrap.local.php')) {
  require __DIR__ . '/bootstrap.local.php';
}
