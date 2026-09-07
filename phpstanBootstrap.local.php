<?php

declare(strict_types = 1);

/**
 * herald-specific phpstan bootstrap. The sibling phpstanBootstrap.php is
 * template-managed (rewritten by `ckinit --update`), so what only this repo
 * needs lives here; phpstan.neon.dist loads both.
 */

$coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';

// Preload Smarty: core's SmartyCompatibility resolves the path via Civi::paths(),
// which is unbooted under phpstan; with the class present it skips that lookup.
$smarty = $coreDir . '/packages/smarty5/Smarty.php';
if (!class_exists('Smarty', autoload: FALSE) && is_file($smarty)) {
  require_once $smarty;
}

// Extension classes (CRM_Civirules_Action …) are only known to a booted site;
// phpstan dies on the parent lookup without this PSR-0 style loader.
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

// civirules.civix.php aliases CRM_Civirules_DAO_Base at runtime; no file declares it.
if (class_exists('CRM_Core_DAO_Base') && !class_exists('CRM_Civirules_DAO_Base', autoload: FALSE)) {
  class_alias('CRM_Core_DAO_Base', 'CRM_Civirules_DAO_Base');
}
