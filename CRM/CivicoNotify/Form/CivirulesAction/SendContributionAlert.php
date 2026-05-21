<?php

use CRM_CivicoNotify_ExtensionUtil as E;

class CRM_CivicoNotify_Form_CivirulesAction_SendContributionAlert extends CRM_CivicoNotify_Form_CivirulesAction_AbstractSendForm {

  protected function getTemplateOptions(): array {
    $rows = \Civi\Api4\MessageTemplate::get(FALSE)
      ->addSelect('workflow_name', 'msg_title')
      ->addWhere('workflow_name', 'LIKE', 'civico_notify_contribution_%')
      ->addWhere('workflow_name', 'NOT LIKE', 'civico_notify_contribution_digest_%')
      ->addWhere('is_default', '=', TRUE)
      ->execute()
      ->getArrayCopy();
    $opts = [];
    foreach ($rows as $row) {
      $opts[$row['workflow_name']] = $row['msg_title'] ?: $row['workflow_name'];
    }
    return $opts;
  }

  protected function addExtraFields(): void {
    $this->add('text', 'campaign_id', E::ts('Campaign ID (optional)'), ['size' => 10]);
    $this->add('text', 'goal', E::ts('Campaign goal (optional, enables progress)'), ['size' => 15]);
    $this->add('text', 'campaign_title', E::ts('Campaign title'), ['size' => 40]);
    $this->civicoCheckbox('include_status_transition', E::ts('Include from_status / to_status (uses pre-edit snapshot)'));
  }

  protected function getExtraValues(array $submitted): array {
    return [
      'campaign_id' => $submitted['campaign_id'] !== '' ? (int) $submitted['campaign_id'] : NULL,
      'goal' => $submitted['goal'] !== '' ? (float) $submitted['goal'] : NULL,
      'campaign_title' => $submitted['campaign_title'] ?? '',
      'include_status_transition' => !empty($submitted['include_status_transition']),
    ];
  }

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
