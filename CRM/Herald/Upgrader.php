<?php

use CRM_Herald_ExtensionUtil as E;

class CRM_Herald_Upgrader extends \CRM_Extension_Upgrader_Base {

  public function enable(): void {
    if (!method_exists('CRM_Civirules_Utils_Upgrader', 'insertActionsFromJson')) {
      throw new Exception('CiviRules extension is required by herald but not enabled.');
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
