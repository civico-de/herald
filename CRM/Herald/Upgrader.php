<?php

use CRM_Herald_ExtensionUtil as E;

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

  public function enable(): void {
    // phpstan resolves CRM_Civirules_Utils_Upgrader via the scanDirectories
    // entry for org.civicoop.civirules (needed so the classes are known at
    // all), which makes this method_exists() look permanently true from a
    // static-analysis standpoint. At runtime the guard is real: CiviRules
    // may not be installed/enabled at all, and this call would otherwise
    // fatal with an undefined-class error before we can raise our own
    // message.
    // @phpstan-ignore function.alreadyNarrowedType
    if (!method_exists('CRM_Civirules_Utils_Upgrader', 'insertActionsFromJson')) {
      throw new Exception('CiviRules extension is required by herald but not enabled.');
    }
    // When herald and CiviRules are installed in the SAME process (fresh
    // install, headless tests), the entity registry was built before
    // CiviRules came online and Api4 "CiviRulesAction" is unknown. Flush so
    // it rebuilds with CiviRules' entities included.
    if (class_exists('\Civi\Schema\EntityRepository')
      && !\Civi\Schema\EntityRepository::entityExists('CiviRulesAction')) {
      \Civi\Schema\EntityRepository::flush();
    }
    CRM_Civirules_Utils_Upgrader::insertActionsFromJson(
      $this->extensionDir . DIRECTORY_SEPARATOR . 'civirules_actions.json'
    );
    // Same rationale as the enable() guard above: phpstan sees this as
    // always true because of scanDirectories, but the method may genuinely
    // be absent on older CiviRules versions at runtime.
    // @phpstan-ignore function.alreadyNarrowedType
    if (method_exists('CRM_Civirules_Utils_Upgrader', 'insertConditionsFromJson')) {
      CRM_Civirules_Utils_Upgrader::insertConditionsFromJson(
        $this->extensionDir . DIRECTORY_SEPARATOR . 'civirules_conditions.json'
      );
    }
  }

}
