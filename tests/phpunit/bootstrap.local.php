<?php

declare(strict_types = 1);

/**
 * Repo-specific test setup — the sibling bootstrap.php is template-managed and
 * rewritten wholesale by `ckinit --update`, so anything herald-specific lives
 * here.
 */

// The extension's own classes (psr-0/psr-4 in composer.json) and the bundled
// packages Strauss prefixed; `composer install` builds vendor/.
require_once __DIR__ . '/../../vendor/autoload.php';

// CiviRules' base classes (parents of herald's actions/conditions) must be
// loadable BEFORE the first headless test boots Civi — the pure (@group unit)
// CRM_ tests run first and no extension classloader is active yet. In the CI
// stack CiviRules sits next to herald in the ext dir; elsewhere point
// CIVIRULES_PATH at a checkout.
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
