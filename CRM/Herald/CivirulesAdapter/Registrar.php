<?php

declare(strict_types = 1);

/**
 * CiviRules instantiates handlers from civirule_action/condition.class_name,
 * so a class rename is a data migration; migrateRenamedClasses() also covers
 * rows whose `name` an admin has changed and the JSON import cannot match.
 */
final class CRM_Herald_CivirulesAdapter_Registrar {

  /**
   * Retired handler class names → their successors.
   */
  private const RENAMED_CLASSES = [
    'CRM_Herald_CivirulesActions_SendNotification' => 'CRM_Herald_CivirulesAdapter_Action_Notification',
  ];

  /**
   * $extensionDir is herald's root.
   */
  public static function register(string $extensionDir): void {
    // phpstan sees CiviRules via scanDirectories and thinks this is always true;
    // at runtime CiviRules may be absent and the call would fatal.
    // @phpstan-ignore function.alreadyNarrowedType
    if (!method_exists('CRM_Civirules_Utils_Upgrader', 'insertActionsFromJson')) {
      throw new Exception('CiviRules extension is required by herald but not enabled.');
    }
    // Installed in the same process as CiviRules (fresh install, headless tests),
    // the entity registry predates CiviRules' entities.
    if (
      class_exists('\Civi\Schema\EntityRepository')
      && !\Civi\Schema\EntityRepository::entityExists('CiviRulesAction')
    ) {
      \Civi\Schema\EntityRepository::flush();
    }
    CRM_Civirules_Utils_Upgrader::insertActionsFromJson(
      $extensionDir . DIRECTORY_SEPARATOR . 'civirules_actions.json',
    );
    // Same phpstan caveat as above; absent on older CiviRules versions.
    // @phpstan-ignore function.alreadyNarrowedType
    if (method_exists('CRM_Civirules_Utils_Upgrader', 'insertConditionsFromJson')) {
      CRM_Civirules_Utils_Upgrader::insertConditionsFromJson(
        $extensionDir . DIRECTORY_SEPARATOR . 'civirules_conditions.json',
      );
    }
  }

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
