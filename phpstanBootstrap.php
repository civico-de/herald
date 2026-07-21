<?php
declare(strict_types = 1);

/**
 * phpstan bootstrap: register CiviCRM's class loader so core symbols
 * (Civi, CRM_*, Civi\Api4\*) resolve. Runs inside the dev container
 * (civikitchen standalone layout); override CIVICRM_CORE_DIR if needed.
 */
$coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';

require_once $coreDir . '/vendor/autoload.php';
require_once $coreDir . '/CRM/Core/ClassLoader.php';
\CRM_Core_ClassLoader::singleton()->register();
require_once $coreDir . '/api/api.php';

// Preload Smarty. Core's CRM/Core/SmartyCompatibility.php resolves the Smarty
// path through \Civi::paths(), which needs a booted container — under phpstan it
// is null, and autoloading CRM_Core_Smarty dies with "Call to a member function
// getPath() on null". The file skips that lookup entirely when a Smarty class is
// already present, so load the same one the runtime would.
$smarty = $coreDir . '/packages/smarty5/Smarty.php';
if (!class_exists('Smarty', FALSE) && is_file($smarty)) {
  require_once $smarty;
}

// Extension classes (CRM_Civirules_Action and friends) are registered by
// CiviCRM's extension system, which only exists once a site is booted — the core
// class loader above does not know them. PHPStan resolves a parent class through
// the autoloader when its own scan has not indexed it, so without this it dies
// with "Internal error: Class CRM_Civirules_Action not found" instead of
// reporting anything useful.
//
// Extensions follow the same PSR-0-ish layout as core (CRM_Foo_Bar =>
// CRM/Foo/Bar.php), so map the class name onto each extension root.
$extDir = getenv('CIVICRM_EXT_DIR') ?: dirname($coreDir) . '/ext';
spl_autoload_register(static function (string $class) use ($extDir): void {
  if (!str_starts_with($class, 'CRM_')) {
    return;
  }
  $relative = str_replace('_', '/', $class) . '.php';
  foreach (glob($extDir . '/*', GLOB_ONLYDIR) ?: [] as $ext) {
    $file = $ext . '/' . $relative;
    if (is_file($file)) {
      require_once $file;

      return;
    }
  }
});

// Some extension classes have no file at all: CiviRules' civix shim aliases
// CRM_Civirules_DAO_Base onto CRM_Core_DAO_Base from inside an autoload hook
// (civirules.civix.php), so nothing on disk declares it. Reproduce the alias the
// runtime would create rather than leaving phpstan to die on the parent lookup.
if (class_exists('CRM_Core_DAO_Base') && !class_exists('CRM_Civirules_DAO_Base', FALSE)) {
  class_alias('CRM_Core_DAO_Base', 'CRM_Civirules_DAO_Base');
}

// Settings-defined runtime constant; phpstan only needs it to exist.
defined('CIVICRM_UF_BASEURL') || define('CIVICRM_UF_BASEURL', 'http://localhost');
