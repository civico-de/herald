<?php

use CRM_CivicoNotify_ExtensionUtil as E;

class CRM_CivicoNotify_Upgrader extends \CRM_Extension_Upgrader_Base {

  public function enable(): void {
    if (!method_exists('CRM_Civirules_Utils_Upgrader', 'insertActionsFromJson')) {
      throw new Exception('CiviRules extension is required by civico_notify but not enabled.');
    }
    CRM_Civirules_Utils_Upgrader::insertActionsFromJson(
      $this->extensionDir . DIRECTORY_SEPARATOR . 'civirules_actions.json'
    );
    if (method_exists('CRM_Civirules_Utils_Upgrader', 'insertConditionsFromJson')) {
      CRM_Civirules_Utils_Upgrader::insertConditionsFromJson(
        $this->extensionDir . DIRECTORY_SEPARATOR . 'civirules_conditions.json'
      );
    }
  }

}
