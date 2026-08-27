<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Form for the contribution-alert action.
 * @mago-expect lint:cyclomatic-complexity
 */
class CRM_Herald_CivirulesAdapter_Form_SendContributionAlert extends CRM_Herald_CivirulesAdapter_Form_AbstractSend {

  /**
   * @return array<string, string>
   */
  protected function getTemplateOptions(): array {
    $rows = \Civi\Api4\MessageTemplate::get(checkPermissions: FALSE)
      ->addSelect('workflow_name', 'msg_title')
      ->addWhere('workflow_name', 'LIKE', 'herald_contribution_%')
      ->addWhere('workflow_name', 'NOT LIKE', 'herald_contribution_digest_%')
      ->addWhere('is_default', '=', value: TRUE)
      ->execute()
      ->getArrayCopy();
    $opts = [];
    foreach ($rows as $row) {
      if (!is_array($row) || !is_string($row['workflow_name'] ?? NULL)) {
        continue;
      }
      $workflowName = $row['workflow_name'];
      $msgTitle = $row['msg_title'] ?? NULL;
      $msgTitle = is_string($msgTitle) ? $msgTitle : '';
      $opts[$workflowName] = $msgTitle !== '' ? $msgTitle : $workflowName;
    }
    return $opts;
  }

  protected function addExtraFields(): void {
    $this->add('text', 'campaign_id', E::ts('Campaign ID (optional)'), ['size' => 10]);
    $this->add('text', 'goal', E::ts('Campaign goal (optional, enables progress)'), ['size' => 15]);
    $this->add('text', 'campaign_title', E::ts('Campaign title'), ['size' => 40]);
    $this->heraldCheckbox(
      'include_status_transition',
      E::ts('Include from_status / to_status (uses pre-edit snapshot)'),
    );
  }

  /**
   * @param array<string, mixed> $submitted
   * @return array<string, mixed>
   */
  protected function getExtraValues(array $submitted): array {
    $campaignId = self::toStringValue($submitted['campaign_id'] ?? '');
    $goal = self::toStringValue($submitted['goal'] ?? '');
    return [
      'campaign_id' => $campaignId !== '' ? (int) $campaignId : NULL,
      'goal' => $goal !== '' ? (float) $goal : NULL,
      'campaign_title' => $submitted['campaign_title'] ?? '',
      'include_status_transition' => !empty($submitted['include_status_transition']),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  protected function extraDefaults(): array {
    $params = $this->getActionParameters();
    return [
      'campaign_id' => $params['campaign_id'] ?? '',
      'goal' => $params['goal'] ?? '',
      'campaign_title' => $params['campaign_title'] ?? '',
      'include_status_transition' => $params['include_status_transition'] ?? TRUE,
    ];
  }

}
