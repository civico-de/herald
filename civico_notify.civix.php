<?php

// AUTO-GENERATED FILE -- Civix may overwrite any changes made to this file

class CRM_CivicoNotify_ExtensionUtil {
  const SHORT_NAME = 'civico_notify';
  const LONG_NAME = 'civico_notify';
  const CLASS_PREFIX = 'CRM_CivicoNotify';

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

use CRM_CivicoNotify_ExtensionUtil as E;

function _civico_notify_civix_civicrm_config($config = NULL) {
  static $configured = FALSE;
  if ($configured) {
    return;
  }
  $configured = TRUE;

  $extRoot = __DIR__ . DIRECTORY_SEPARATOR;
  $include_path = $extRoot . PATH_SEPARATOR . get_include_path();
  set_include_path($include_path);
}

function _civico_notify_civix_civicrm_install() {
  _civico_notify_civix_civicrm_config();
}

function _civico_notify_civix_civicrm_enable(): void {
  _civico_notify_civix_civicrm_config();
}
