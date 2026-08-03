<?php

/**
 * Repo-specific test setup — the sibling bootstrap.php is template-managed and
 * rewritten wholesale by `ckinit --update`, so anything herald-specific lives
 * here.
 */

// The extension's own classes come from composer's autoload (psr-0/psr-4 in
// composer.json); the dev-deps the suite mocks with (Guzzle's MockHandler)
// ride along. Conditional because vendor/ is not tracked — CI runs against
// what the image and CiviCRM core already provide.
$heraldVendor = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($heraldVendor)) {
  require_once $heraldVendor;
}

// CiviRules' base classes (parents of herald's actions/conditions) must be
// loadable BEFORE the first headless test boots Civi — the pure (@group unit)
// CRM_ tests run first and no extension classloader is active yet. In the CI
// stack CiviRules sits next to herald in the ext dir; elsewhere point
// CIVIRULES_PATH at a checkout.
// @mago-expect lint:no-shorthand-ternary
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
