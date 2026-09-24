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
   * The buffer flush claims the rows it delivers; existing buffer tables need
   * the claim columns to store that mark.
   */
  public function upgrade_1004(): bool {
    $this->ctx->log->info('herald: adding claim columns to civicrm_herald_buffer');
    \Civi\Herald\Buffer\AlertBuffer::addClaimColumns();
    return TRUE;
  }

  /**
   * The buffer flush parks items it can never deliver; existing buffer tables
   * need the dead-letter columns to hold that state.
   */
  public function upgrade_1005(): bool {
    $this->ctx->log->info('herald: adding dead-letter columns to civicrm_herald_buffer');
    \Civi\Herald\Buffer\AlertBuffer::addDeadLetterColumns();
    return TRUE;
  }

  /**
   * herald_rule_health is no longer a declared setting; SQL, because the
   * settings API refuses a name without metadata.
   */
  public function upgrade_1006(): bool {
    $this->ctx->log->info('herald: removing the retired herald_rule_health setting');
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_setting WHERE name = 'herald_rule_health'");
    return TRUE;
  }

  /**
   * Bring CiviRules manifests, renamed handler classes and managed entities
   * in line with the shipped code. Shared by enable() and upgrade steps.
   */
  public static function reconcile(string $extensionDir): void {
    CRM_Herald_CivirulesAdapter_Registrar::register($extensionDir);
    CRM_Herald_CivirulesAdapter_Registrar::migrateRenamedClasses();
    CRM_Core_ManagedEntities::singleton(fresh: TRUE)->reconcile(['herald']);
  }

  public function enable(): void {
    // On install, enable() runs inside core's install loop; an extension installed in the
    // same call (CiviRules, one `Extension.install` for both) has no Api4 entities yet, the
    // container reloads only after the loop. postInstall() runs after that reload.
    $processes = CRM_Extension_System::singleton()->getManager()->getActiveProcesses('herald');
    if (in_array('installing', $processes, TRUE)) {
      return;
    }
    self::reconcile($this->extensionDir);
  }

  public function postInstall(): void {
    self::reconcile($this->extensionDir);
  }

}
