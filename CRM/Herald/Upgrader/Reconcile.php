<?php

declare(strict_types = 1);

/**
 * Bring CiviRules manifests, renamed handler classes and managed entities in
 * line with the shipped code. Shared by enable(), postInstall() and upgrade steps.
 */
final class CRM_Herald_Upgrader_Reconcile {

  public static function run(string $extensionDir): void {
    CRM_Herald_CivirulesAdapter_Registrar::register($extensionDir);
    CRM_Herald_CivirulesAdapter_Registrar::migrateRenamedClasses();
    CRM_Herald_CivirulesAdapter_RetiredHandlers::migrate();
    CRM_Core_ManagedEntities::singleton(fresh: TRUE)->reconcile(['herald']);
  }

}
