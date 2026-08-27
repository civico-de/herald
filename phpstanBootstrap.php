<?php

declare(strict_types = 1);

/**
 * phpstan bootstrap: register CiviCRM's class loader so core symbols
 * (Civi, CRM_*, Civi\Api4\*) resolve, then the classes of every extension
 * this one <requires> that is present under the ext dir. Runs inside the dev
 * container (civikitchen standalone layout); override CIVICRM_CORE_DIR and
 * CK_EXT_DIR if needed.
 */
$coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';

require_once $coreDir . '/vendor/autoload.php';
require_once $coreDir . '/CRM/Core/ClassLoader.php';
\CRM_Core_ClassLoader::singleton()->register();
require_once $coreDir . '/api/api.php';

// Settings-defined runtime constant; phpstan only needs it to exist.
defined('CIVICRM_UF_BASEURL') || define('CIVICRM_UF_BASEURL', 'http://localhost');

// Required extensions live off core's classloader path. Each one mounted or
// downloaded under the ext dir (named by its key, as the images do) gets the
// civix layout autoloaded: CRM_* by underscore, Civi\* PSR-4, api_* by
// underscore, plus its own vendor/. A required key with no directory is only
// noted — analysis of code that never touches it is still complete, and code
// that does gets phpstan's honest "unknown class".
$ckExtDir = getenv('CK_EXT_DIR') ?: '/var/www/html/ext';
libxml_use_internal_errors(use_errors: TRUE);
$ckInfo = simplexml_load_file(__DIR__ . '/info.xml');
foreach ($ckInfo === FALSE ? [] : $ckInfo->requires->ext ?? [] as $ckRequired) {
  $ckKey = trim((string) $ckRequired);
  $ckDir = $ckExtDir . '/' . $ckKey;
  if ($ckKey === '' || !is_dir($ckDir)) {
    fwrite(
      STDERR,
      "phpstanBootstrap: required extension {$ckKey} is not under {$ckExtDir}; its classes stay unresolved\n",
    );
    continue;
  }
  if (is_file($ckDir . '/vendor/autoload.php')) {
    require_once $ckDir . '/vendor/autoload.php';
  }
  spl_autoload_register(static function (string $class) use ($ckDir): void {
    $file = match (TRUE) {
      str_starts_with($class, 'Civi\\') => $ckDir . '/Civi/' . str_replace('\\', '/', substr($class, 5)) . '.php',
      str_starts_with($class, 'CRM_'), str_starts_with($class, 'api_') => $ckDir
        . '/'
        . str_replace('_', '/', $class)
        . '.php',
      default => NULL,
    };
    if ($file !== NULL && is_file($file)) {
      require_once $file;
    }
  });
}
