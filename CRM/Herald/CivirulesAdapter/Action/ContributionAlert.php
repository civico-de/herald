<?php

declare(strict_types = 1);

/**
 * CiviRules adapter: send a single-contribution alert (created, updated,
 * status_changed). Unpacks the Contribution and CiviRules' pre-edit status,
 * then calls Herald.notifyContribution.
 */
class CRM_Herald_CivirulesAdapter_Action_ContributionAlert extends CRM_Herald_CivirulesAdapter_Action_AbstractSend {

  protected function getFormPath(): string {
    return 'civicrm/herald/form/civirulesaction/sendcontributionalert';
  }

  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
    $contribution = $triggerData->getEntityData('Contribution');
    if (empty($contribution) || !is_array($contribution)) {
      throw new \CRM_Core_Exception('herald: SendContributionAlert requires a Contribution in trigger data.');
    }

    // CiviRules evaluates rules in a system context (cron, post-commit
    // queue) — there is no acting user whose ACLs could apply.
    // phpcs:ignore CiviKitchen.Security.PermissionBypass -- system context, see above
    civicrm_api4('Herald', 'notifyContribution', [
      'checkPermissions' => FALSE,
      'actionParams' => $this->getActionParameters(),
      'contribution' => $contribution,
      'originalStatusId' => CRM_Herald_CivirulesAdapter_OriginalData::intField(
        $triggerData,
        'Contribution',
        'contribution_status_id',
      ),
    ]);
  }

}
