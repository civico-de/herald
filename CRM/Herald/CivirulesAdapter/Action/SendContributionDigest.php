<?php

declare(strict_types = 1);

/**
 * CiviRules adapter: send a contribution digest (daily/weekly/custom).
 *
 * The digest needs no trigger entity — everything comes from the stored action
 * parameters, so this only forwards them to Herald.notifyDigest.
 */
class CRM_Herald_CivirulesAdapter_Action_SendContributionDigest extends CRM_Herald_CivirulesAdapter_Action_AbstractSend {

  protected function getFormPath(): string {
    return 'civicrm/herald/form/civirulesaction/sendcontributiondigest';
  }

  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
    civicrm_api4('Herald', 'notifyDigest', [
      'checkPermissions' => FALSE,
      'actionParams' => $this->getActionParameters(),
    ]);
  }

}
