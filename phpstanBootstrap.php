<?php

declare(strict_types = 1);

/**
 * phpstan bootstrap: register CiviCRM's class loader so core symbols
 * (Civi, CRM_*, Civi\Api4\*) resolve, then the core component extensions
 * shipped under the core ext dir, then the classes of every extension this
 * one <requires> that is present under the ext dir. The repo's own classes
 * come from phpstan's paths; only their runtime-only civix DAO_Base alias and
 * core's APIv3 helpers and CiviMix\Schema library come from here. Runs inside the dev
 * container (civikitchen standalone layout); override CIVICRM_CORE_DIR and
 * CK_EXT_DIR if needed.
 */
$coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';

require_once $coreDir . '/vendor/autoload.php';
require_once $coreDir . '/CRM/Core/ClassLoader.php';
\CRM_Core_ClassLoader::singleton()->register();
require_once $coreDir . '/api/api.php';
// APIv3 functions return through civicrm_api3_create_success() and friends.
require_once $coreDir . '/api/v3/utils.php';

// Settings-defined runtime constant; phpstan only needs it to exist.
defined('CIVICRM_UF_BASEURL') || define('CIVICRM_UF_BASEURL', 'http://localhost');

// civix's E::schema() returns core's CiviMix\Schema library, which core loads
// through pathload rather than its class loader.
$ckSchemaLibs = glob($coreDir . '/mixin/lib/civimix-schema@*/src') ?: [];
natsort($ckSchemaLibs);
$ckSchemaLib = end($ckSchemaLibs);
spl_autoload_register(static function (string $class) use ($ckSchemaLib): void {
  if ($ckSchemaLib !== FALSE && str_starts_with($class, 'CiviMix\\Schema\\')) {
    $file = $ckSchemaLib . '/' . str_replace('\\', '/', substr($class, 15)) . '.php';
    if (is_file($file)) {
      require_once $file;
    }
  }
});

/**
 * civix DAOs extend CRM_<Prefix>_DAO_Base, which no file defines: the
 * extension's civix loader aliases it to core's CRM_Core_DAO_Base at runtime.
 */
$ckDaoBase = static function (string $class, string $ckDir): bool {
  if (preg_match('/^CRM_(\w+)_DAO_Base$/', $class, $m) !== 1 || !is_dir($ckDir . '/CRM/' . $m[1])) {
    return FALSE;
  }
  class_alias('CRM_Core_DAO_Base', $class);
  return TRUE;
};
spl_autoload_register(static fn (string $class): bool => $ckDaoBase($class, __DIR__));

/**
 * Autoload one extension directory in the civix layout: CRM_* and api_* by
 * underscore, Civi\* PSR-4, plus its own vendor/. Its root goes on the include
 * path as civix's config hook puts it there, for classes that require their
 * bundled packages by a relative path.
 */
$ckRegister = static function (string $ckDir) use ($ckDaoBase): void {
  set_include_path($ckDir . PATH_SEPARATOR . get_include_path());
  if (is_file($ckDir . '/vendor/autoload.php')) {
    require_once $ckDir . '/vendor/autoload.php';
  }
  spl_autoload_register(static function (string $class) use ($ckDir, $ckDaoBase): void {
    if ($ckDaoBase($class, $ckDir)) {
      return;
    }
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
};

// Core component extensions (civi_member, civi_contribute, …) ship with core
// but live off its classloader path, so Civi\Api4\Membership and friends are
// unknown classes without this. Registered by layout, not by a name list: a
// package whose classes sit somewhere else (flexmailer's src/) is not covered
// and stays a scanDirectories entry. Found by info.xml one or two levels deep:
// afform keeps its extensions a level down (ext/afform/core).
libxml_use_internal_errors(use_errors: TRUE);
$ckCoreKeys = [];
foreach (array_merge(
  glob($coreDir . '/ext/*/info.xml') ?: [],
  glob($coreDir . '/ext/*/*/info.xml') ?: [],
) as $ckCoreInfo) {
  $ckCoreExt = dirname($ckCoreInfo);
  $ckCoreXml = simplexml_load_file($ckCoreInfo);
  if ($ckCoreXml !== FALSE) {
    $ckCoreKeys[] = (string) $ckCoreXml['key'];
  }
  if (is_dir($ckCoreExt . '/Civi') || is_dir($ckCoreExt . '/CRM')) {
    $ckRegister($ckCoreExt);
  }
}

// Other required extensions live off core's classloader path. Each one mounted or
// downloaded under the ext dir (named by its key, as the images do) gets the
// civix layout autoloaded: CRM_* by underscore, Civi\* PSR-4, api_* by
// underscore, plus its own vendor/. A required key with no directory is only
// noted — analysis of code that never touches it is still complete, and code
// that does gets phpstan's honest "unknown class".
$ckExtDir = getenv('CK_EXT_DIR') ?: '/var/www/html/ext';
$ckInfo = simplexml_load_file(__DIR__ . '/info.xml');
foreach ($ckInfo === FALSE ? [] : $ckInfo->requires->ext ?? [] as $ckRequired) {
  $ckKey = trim((string) $ckRequired);
  if (in_array($ckKey, $ckCoreKeys, TRUE)) {
    continue;
  }
  $ckDir = $ckExtDir . '/' . $ckKey;
  if ($ckKey === '' || !is_dir($ckDir)) {
    fwrite(
      STDERR,
      "phpstanBootstrap: required extension {$ckKey} is not under {$ckExtDir}; its classes stay unresolved\n",
    );
    continue;
  }
  $ckRegister($ckDir);
}
