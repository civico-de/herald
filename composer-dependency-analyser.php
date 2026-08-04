<?php

declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

/**
 * ckdeps config for this repo. Two additions over the bundled CiviKitchen
 * default:
 *
 *  - vendor/ is excluded. The analyser scans the working directory, and with
 *    guzzle installed that means grading every dependency's own source — the
 *    47 "shadow dependencies" in a stock run are phpunit's and guzzle's, not
 *    herald's.
 *  - core's global `Civi` class (`\Civi::log()`, `\Civi::settings()`) has no
 *    namespace, so the default `Civi\` pattern does not cover it.
 */
return (new Configuration())
  ->ignoreUnknownClassesRegex('~^(CRM_|Civi\\\\|Civi$|CiviCRM)~')
  ->ignoreUnknownFunctionsRegex('~^(civicrm_|civi)~')
  ->addPathToExclude(__DIR__ . '/vendor')
  // tests/ is dev code; phpunit there is not a production dependency.
  ->addPathToScan(__DIR__ . '/tests', TRUE)
  ->disableReportingUnmatchedIgnores();
