<?php

// AUTO-GENERATED FILE -- Civix may overwrite any changes made to this file

class CRM_Herald_ExtensionUtil {
  const SHORT_NAME = 'herald';
  const LONG_NAME = 'herald';
  const CLASS_PREFIX = 'CRM_Herald';

  public static function ts($text, $params = []): string {
    if (!array_key_exists('domain', $params)) {
      $params['domain'] = [self::LONG_NAME, NULL];
    }
    return ts($text, $params);
  }

  public static function url($file = NULL): string {
    if ($file === NULL) {
      return rtrim(CRM_Core_Resources::singleton()->getUrl(self::LONG_NAME), '/');
    }
    return CRM_Core_Resources::singleton()->getUrl(self::LONG_NAME, $file);
  }

  public static function path($file = NULL) {
    return __DIR__ . ($file === NULL ? '' : (DIRECTORY_SEPARATOR . $file));
  }

  public static function findClass($suffix) {
    return self::CLASS_PREFIX . '_' . str_replace('\\', '_', $suffix);
  }
}

use CRM_Herald_ExtensionUtil as E;

function _herald_civix_civicrm_config($config = NULL) {
  static $configured = FALSE;
  if ($configured) {
    return;
  }
  $configured = TRUE;

  $extRoot = __DIR__ . DIRECTORY_SEPARATOR;
  $include_path = $extRoot . PATH_SEPARATOR . get_include_path();
  set_include_path($include_path);
}

function _herald_civix_civicrm_install() {
  _herald_civix_civicrm_config();
}

function _herald_civix_civicrm_enable(): void {
  _herald_civix_civicrm_config();
}
