<?php

declare(strict_types = 1);

/**
 * Bring managed entities (CiviRules handlers among them) and renamed or retired
 * handler classes in line with the shipped code. Shared by enable(), postInstall() and upgrade steps.
 */
final class CRM_Herald_Upgrader_Reconcile {

  public static function run(): void {
    CRM_Core_ManagedEntities::singleton(fresh: TRUE)->reconcile(['herald']);
    CRM_Herald_CivirulesAdapter_Registrar::migrateRenamedClasses();
    CRM_Herald_CivirulesAdapter_RetiredHandlers::migrate();
  }

}
