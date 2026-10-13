<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Install and uninstall of civicrm_herald_buffer run through the
 * AutomaticUpgrader from schema/; the steps here bring older tables up to it.
 */
class CRM_Herald_Upgrader extends \CRM_Extension_Upgrader_Base {

  private const BUFFER_SCHEMA = 'schema/HeraldBufferedAlert.entityType.php';

  /**
   * Existing installs: add the alert aggregation buffer table.
   */
  public function upgrade_1001(): bool {
    $this->ctx->log->info('herald: creating civicrm_herald_buffer');
    E::schema()->createEntityTable(self::BUFFER_SCHEMA);
    return TRUE;
  }

  /**
   * The CiviRules handlers moved into CRM/Herald/CivirulesAdapter/; re-register
   * them and rewrite any class_name still naming an old class.
   */
  public function upgrade_1002(): bool {
    $this->ctx->log->info('herald: migrating CiviRules handler classes to the adapter namespace');
    CRM_Core_ManagedEntities::singleton(fresh: TRUE)->reconcile(['herald']);
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
    $this->ctx->log->info('herald: re-registering CiviRules handlers and reconciling managed entities');
    CRM_Herald_Upgrader_Reconcile::run();
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
   * Retired special-case handlers fold into "Send notification", "Send digest"
   * and "Field transition"; contribution digest jobs become APIv4 jobs of Herald.notifyDigest.
   */
  public function upgrade_1007(): bool {
    $this->ctx->log->info('herald: moving rules onto the generic handlers');
    CRM_Core_ManagedEntities::singleton(fresh: TRUE)->reconcile(['herald']);
    $migrated = CRM_Herald_CivirulesAdapter_RetiredHandlers::migrate();
    $this->ctx->log->info("herald: moved {$migrated} rule action(s) and condition(s)");
    $jobs = CRM_Herald_Upgrader_DigestJobs::migrate();
    $this->ctx->log->info("herald: moved {$jobs} digest job(s) to Herald.notifyDigest");
    return TRUE;
  }

  /**
   * Buffer tables from older releases lack columns, column comments or the row
   * format of schema/; bring them in line.
   */
  public function upgrade_1008(): bool {
    $this->ctx->log->info('herald: aligning civicrm_herald_buffer with schema/');
    CRM_Herald_Upgrader_BufferTable::sync();
    return TRUE;
  }

  /**
   * Existing installs: the self-service preferences profile, formerly a
   * one-off script; a profile that script created is kept.
   */
  public function upgrade_1009(): bool {
    $this->ctx->log->info('herald: ensuring the herald_my_preferences profile');
    CRM_Herald_Upgrader_PreferencesProfile::ensure();
    return TRUE;
  }

  /**
   * Existing installs: register the "Herald: Schedule" trigger.
   */
  public function upgrade_1010(): bool {
    $this->ctx->log->info('herald: registering the Herald: Schedule CiviRules trigger');
    CRM_Herald_Upgrader_Reconcile::run();
    return TRUE;
  }

  public function enable(): void {
    // On install, enable() runs inside core's install loop; an extension installed in the
    // same call (CiviRules, one `Extension.install` for both) has no Api4 entities yet, the
    // container reloads only after the loop. postInstall() runs after that reload.
    $processes = CRM_Extension_System::singleton()->getManager()->getActiveProcesses('herald');
    if (in_array('installing', $processes, TRUE)) {
      return;
    }
    CRM_Herald_Upgrader_Reconcile::run();
  }

  public function postInstall(): void {
    // An uninstall by an older release left the buffer table in place, and
    // CREATE TABLE IF NOT EXISTS keeps it as it was.
    CRM_Herald_Upgrader_BufferTable::sync();
    CRM_Herald_Upgrader_Reconcile::run();
    CRM_Herald_Upgrader_PreferencesProfile::ensure();
  }

}
