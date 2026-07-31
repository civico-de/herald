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

// Settings-defined runtime constant; phpstan only needs it to exist.
defined('CIVICRM_UF_BASEURL') || define('CIVICRM_UF_BASEURL', 'http://localhost');
