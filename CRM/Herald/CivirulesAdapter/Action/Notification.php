<?php

declare(strict_types = 1);

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_Civirules_Action')) {
  /**
   * CiviRules adapter: render any workflow template and send it through any channel.
   *
   * Unpacks the trigger's entity payloads into a plain context array and calls
   * Herald.notify, which builds the template variables and dispatches.
   */
  class CRM_Herald_CivirulesAdapter_Action_Notification extends CRM_Herald_CivirulesAdapter_Action_AbstractSend {

    protected function getFormPath(): string {
      return 'civicrm/herald/form/civirulesaction/sendnotification';
    }

    public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
      // CiviRules evaluates rules in a system context (cron, post-commit
      // queue) — there is no acting user whose ACLs could apply.
      \Civi\Api4\Herald::notify(checkPermissions: FALSE)
        ->setActionParams($this->getActionParameters())
        ->setTriggerContext([
          'contact_id' => (int) $triggerData->getContactId(),
          'Contribution' => $triggerData->getEntityData('Contribution'),
          'GroupContact' => $triggerData->getEntityData('GroupContact'),
          'Membership' => $triggerData->getEntityData('Membership'),
          'Participant' => $triggerData->getEntityData('Participant'),
          'Event' => $triggerData->getEntityData('Event'),
          'original' => CRM_Herald_CivirulesAdapter_OriginalData::all($triggerData),
        ])
        ->execute();
    }

  }
}
