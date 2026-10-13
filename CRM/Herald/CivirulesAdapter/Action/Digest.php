<?php

declare(strict_types = 1);

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_Civirules_Action')) {
  /**
   * CiviRules adapter: send a digest. It needs no trigger entity — everything
   * comes from the stored action parameters, so this only forwards them to
   * Herald.notifyDigest.
   */
  class CRM_Herald_CivirulesAdapter_Action_Digest extends CRM_Herald_CivirulesAdapter_Action_AbstractSend {

    protected function getFormPath(): string {
      return 'civicrm/herald/form/civirulesaction/senddigest';
    }

    public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
      // CiviRules evaluates rules in a system context (cron, post-commit
      // queue) — there is no acting user whose ACLs could apply.
      \Civi\Api4\Herald::notifyDigest(checkPermissions: FALSE)
        ->setActionParams($this->getActionParameters())
        ->execute();
    }

  }
}
