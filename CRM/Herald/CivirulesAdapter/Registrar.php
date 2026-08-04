<?php

declare(strict_types = 1);

/**
 * Registers herald's adapters with CiviRules and migrates renamed handlers.
 *
 * CiviRules stores the handler class name in civirule_action.class_name /
 * civirule_condition.class_name and instantiates it by string, so a class
 * rename is a data migration. insertActionsFromJson()/insertConditionsFromJson()
 * match on `name` and rewrite class_name, which covers every installation that
 * runs enable() or an upgrade step; migrateRenamedClasses() is the belt for
 * rows whose `name` an admin has since changed.
 */
final class CRM_Herald_CivirulesAdapter_Registrar {

  /**
   * Pre-2026-08 handler classes → their CivirulesAdapter successors.
   */
  private const RENAMED_CLASSES = [
    'CRM_Herald_CivirulesActions_SendNotification' => 'CRM_Herald_CivirulesAdapter_Action_SendNotification',
    'CRM_Herald_CivirulesActions_SendContributionDigest' => 'CRM_Herald_CivirulesAdapter_Action_SendContributionDigest',
    'CRM_Herald_CivirulesActions_SendContributionAlert' => 'CRM_Herald_CivirulesAdapter_Action_SendContributionAlert',
    'CRM_Herald_CivirulesConditions_PreviousContributionStatus' => 'CRM_Herald_CivirulesAdapter_Condition_PreviousContributionStatus',
    'CRM_Herald_CivirulesConditions_EventBecameInactive' => 'CRM_Herald_CivirulesAdapter_Condition_EventBecameInactive',
  ];

  /**
   * Register (or re-register) both manifests. $extensionDir is herald's root.
   */
  public static function register(string $extensionDir): void {
    // phpstan resolves CRM_Civirules_Utils_Upgrader via the scanDirectories
    // entry for org.civicoop.civirules (needed so the classes are known at
    // all), which makes this method_exists() look permanently true from a
    // static-analysis standpoint. At runtime the guard is real: CiviRules may
    // not be installed/enabled at all, and this call would otherwise fatal
    // with an undefined-class error before we can raise our own message.
    // @phpstan-ignore function.alreadyNarrowedType
    if (!method_exists('CRM_Civirules_Utils_Upgrader', 'insertActionsFromJson')) {
      throw new Exception('CiviRules extension is required by herald but not enabled.');
    }
    // When herald and CiviRules are installed in the SAME process (fresh
    // install, headless tests), the entity registry was built before CiviRules
    // came online and Api4 "CiviRulesAction" is unknown. Flush so it rebuilds
    // with CiviRules' entities included.
    if (
      class_exists('\Civi\Schema\EntityRepository')
      && !\Civi\Schema\EntityRepository::entityExists('CiviRulesAction')
    ) {
      \Civi\Schema\EntityRepository::flush();
    }
    CRM_Civirules_Utils_Upgrader::insertActionsFromJson(
      $extensionDir . DIRECTORY_SEPARATOR . 'civirules_actions.json',
    );
    // Same rationale as the guard above: phpstan sees this as always true
    // because of scanDirectories, but the method may genuinely be absent on
    // older CiviRules versions at runtime.
    // @phpstan-ignore function.alreadyNarrowedType
    if (method_exists('CRM_Civirules_Utils_Upgrader', 'insertConditionsFromJson')) {
      CRM_Civirules_Utils_Upgrader::insertConditionsFromJson(
        $extensionDir . DIRECTORY_SEPARATOR . 'civirules_conditions.json',
      );
    }
  }

  /**
   * Point rows still naming a pre-rename handler class at its successor.
   * Configured rules reference the action/condition ROW, so rewriting the
   * class name in place keeps every existing rule working.
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
