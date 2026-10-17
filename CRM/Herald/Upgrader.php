<?php

declare(strict_types = 1);

/**
 * Install and uninstall of the tables in schema/ run through the
 * AutomaticUpgrader; the steps in CRM_Herald_Upgrader_Steps bring older installs up to it.
 */
class CRM_Herald_Upgrader extends CRM_Herald_Upgrader_Steps {

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

  public function uninstall(): void {
    $ids = CRM_Herald_CivirulesAdapter_DependentRules::disable();
    if ($ids !== []) {
      Civi::log('herald')->warning(
        'herald: switched off CiviRules rules that used its handlers: ' . implode(',', $ids),
      );
    }
  }

  public function postInstall(): void {
    // An uninstall by an older release left the buffer table in place, and
    // CREATE TABLE IF NOT EXISTS keeps it as it was.
    CRM_Herald_Upgrader_BufferTable::sync();
    CRM_Herald_Upgrader_Reconcile::run();
    CRM_Herald_Upgrader_PreferencesProfile::ensure();
  }

}
