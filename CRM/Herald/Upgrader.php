<?php

declare(strict_types = 1);

class CRM_Herald_Upgrader extends \CRM_Extension_Upgrader_Base {

  public function install(): void {
    \Civi\Herald\Buffer\AlertBuffer::createTable();
  }

  /**
   * Existing installs: add the alert aggregation buffer table.
   */
  public function upgrade_1001(): bool {
    $this->ctx->log->info('herald: creating civicrm_herald_buffer');
    \Civi\Herald\Buffer\AlertBuffer::createTable();
    return TRUE;
  }

  /**
   * The CiviRules handlers moved into CRM/Herald/CivirulesAdapter/; re-register
   * both manifests and rewrite any class_name still naming an old class.
   */
  public function upgrade_1002(): bool {
    $this->ctx->log->info('herald: migrating CiviRules handler classes to the adapter namespace');
    CRM_Herald_CivirulesAdapter_Registrar::register($this->extensionDir);
    $migrated = CRM_Herald_CivirulesAdapter_Registrar::migrateRenamedClasses();
    $this->ctx->log->info("herald: rewrote {$migrated} CiviRules handler class name(s)");
    return TRUE;
  }

  /**
   * 0.7.0 added the herald_field_transition condition to the CiviRules
   * manifest and the scope fields to the managed custom group; both are only
   * (re)registered on install/enable, so existing installs need a reconcile.
   */
  public function upgrade_1003(): bool {
    $this->ctx->log->info('herald: re-registering CiviRules manifests and reconciling managed entities');
    self::reconcile($this->extensionDir);
    return TRUE;
  }

  /**
   * Bring CiviRules manifests, renamed handler classes and managed entities
   * in line with the shipped code. Shared by enable() and upgrade steps.
   */
  public static function reconcile(string $extensionDir): void {
    CRM_Herald_CivirulesAdapter_Registrar::register($extensionDir);
    CRM_Herald_CivirulesAdapter_Registrar::migrateRenamedClasses();
    CRM_Core_ManagedEntities::singleton(TRUE)->reconcile(['herald']);
  }

  public function enable(): void {
    self::reconcile($this->extensionDir);
  }

}
