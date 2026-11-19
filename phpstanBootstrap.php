<?php

declare(strict_types = 1);

/**
 * phpstan bootstrap: core's class loader, the core component extensions and
 * every <requires> extension under the ext dir. CIVICRM_CORE_DIR and
 * CK_EXT_DIR override the standalone layout.
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
 * Autoload one extension directory in the civix layout plus its vendor/; its
 * root goes on the include path, as civix's config hook puts it there.
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

// Core component extensions live off core's classloader path. Found by info.xml
// one or two levels deep (ext/afform/core); flexmailer's src/ stays a scanDirectories entry.
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

// Required extensions under the ext dir, named by key; a missing one is only
// noted, and code using it gets phpstan's "unknown class".
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
