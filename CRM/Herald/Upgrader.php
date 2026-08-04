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

  public function enable(): void {
    CRM_Herald_CivirulesAdapter_Registrar::register($this->extensionDir);
    CRM_Herald_CivirulesAdapter_Registrar::migrateRenamedClasses();
  }

}
