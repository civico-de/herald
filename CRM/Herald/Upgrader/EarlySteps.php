<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Upgrade steps up to 1009; core also finds inherited upgrade_* methods.
 */
abstract class CRM_Herald_Upgrader_EarlySteps extends \CRM_Extension_Upgrader_Base {

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
   * Formerly created the self-service profile; the "My notifications" form
   * replaces it, and upgrade_1017 removes it.
   */
  public function upgrade_1009(): bool {
    return TRUE;
  }

}
