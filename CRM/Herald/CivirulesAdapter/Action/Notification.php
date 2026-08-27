<?php

declare(strict_types = 1);

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
    civicrm_api4('Herald', 'notify', [
      // phpcs:ignore CiviKitchen.Security.PermissionBypass -- system context, see above
      'checkPermissions' => FALSE,
      'actionParams' => $this->getActionParameters(),
      'triggerContext' => [
        'contact_id' => (int) $triggerData->getContactId(),
        'GroupContact' => $triggerData->getEntityData('GroupContact'),
        'Membership' => $triggerData->getEntityData('Membership'),
        'Participant' => $triggerData->getEntityData('Participant'),
        'Event' => $triggerData->getEntityData('Event'),
      ],
    ]);
  }

}
