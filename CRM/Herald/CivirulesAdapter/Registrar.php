<?php

declare(strict_types = 1);

/**
 * CiviRules instantiates handlers from civirule_action/condition/trigger.class_name,
 * so a class rename is a data migration; migrateRenamedClasses() also covers
 * rows whose `name` an admin has changed and managed/CiviRules.mgd.php cannot match.
 */
final class CRM_Herald_CivirulesAdapter_Registrar {

  /**
   * Retired handler class names → their successors.
   */
  private const RENAMED_CLASSES = [
    'CRM_Herald_CivirulesActions_SendNotification' => 'CRM_Herald_CivirulesAdapter_Action_Notification',
  ];

  /**
   * Rules reference the action/condition row, so rewriting class_name in place
   * keeps them working.
   *
   * @return int Number of rows rewritten.
   */
  public static function migrateRenamedClasses(): int {
    $migrated = 0;
    foreach (['civirule_action', 'civirule_condition'] as $table) {
      if (!\CRM_Core_DAO::checkTableExists($table)) {
        continue;
      }
      foreach (self::RENAMED_CLASSES as $old => $new) {
        $stale = (int) \CRM_Core_DAO::singleValueQuery(
          "SELECT COUNT(*) FROM `{$table}` WHERE class_name = %1",
          [1 => [$old, 'String']],
        );
        if ($stale === 0) {
          continue;
        }
        \CRM_Core_DAO::executeQuery(
          "UPDATE `{$table}` SET class_name = %1 WHERE class_name = %2",
          [1 => [$new, 'String'], 2 => [$old, 'String']],
        );
        $migrated += $stale;
      }
    }
    return $migrated;
  }

}
