<?php

declare(strict_types = 1);

/**
 * CiviRules is optional: its handler classes load, and its tables and APIv4
 * entities exist, only while the extension is installed and enabled.
 */
final class CRM_Herald_CivirulesAdapter_Availability {

  public const EXTENSION = 'org.civicoop.civirules';

  public static function isInstalled(): bool {
    return (
      CRM_Extension_System::singleton()->getManager()->getStatus(self::EXTENSION)
      === CRM_Extension_Manager::STATUS_INSTALLED
    );
  }

}
