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
 *    namespace, so the default `Civi\` pattern does not cover it. Civix's
 *    generated scaffold also calls the CiviCRM-provided CiviMix Schema loader,
 *    which is intentionally not an extension Composer dependency.
 */
return (new Configuration())
  ->ignoreUnknownClassesRegex('~^(CRM_|Civi\\\\|Civi$|CiviCRM|CiviMix\\\\)~')
  ->ignoreUnknownFunctionsRegex('~^(civicrm_|civi|CiviMix\\\\)~')
  ->addPathToExclude(__DIR__ . '/vendor')
  // tests/ and the phpstan bootstrap are dev code: phpunit and the XML
  // extensions they use are not production dependencies.
  ->addPathToScan(__DIR__ . '/tests', isDev: TRUE)
  ->addPathToScan(__DIR__ . '/phpstanBootstrap.php', isDev: TRUE)
  ->disableReportingUnmatchedIgnores();
