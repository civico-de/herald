<?php

use CRM_CivicoNotify_ExtensionUtil as E;

class CRM_CivicoNotify_Form_CivirulesAction_SendContributionDigest extends CRM_CivicoNotify_Form_CivirulesAction_AbstractSendForm {

  protected function getTemplateOptions(): array {
    $rows = \Civi\Api4\MessageTemplate::get(FALSE)
      ->addSelect('workflow_name', 'msg_title')
      ->addWhere('workflow_name', 'LIKE', 'civico_notify_contribution_digest_%')
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
    $this->add('select', 'period', E::ts('Period'), [
      'yesterday' => E::ts('Yesterday'),
      'today' => E::ts('Today'),
      'last_7_days' => E::ts('Last 7 days'),
      'last_30_days' => E::ts('Last 30 days'),
    ], TRUE);
    $this->add('text', 'campaign_id', E::ts('Campaign ID (optional)'), ['size' => 10]);
    $this->add('text', 'financial_type_id', E::ts('Financial type ID (optional)'), ['size' => 10]);
    $this->add('text', 'source_pattern', E::ts('Source LIKE pattern (optional, e.g. %Bank TX%)'), ['size' => 40]);
    $this->civicoCheckbox('show_pending', E::ts('Show pending pool'));
    $this->civicoCheckbox('show_histogram', E::ts('Show histogram'));
    $this->add('text', 'bucket_count', E::ts('Histogram bucket count'), ['size' => 5]);
    $this->add('text', 'bucket_edges', E::ts('Histogram bucket edges (JSON array, optional)'), ['size' => 50, 'placeholder' => '[0, 10, 50, 250, 5000]']);
    $this->add('text', 'goal', E::ts('Campaign goal (optional, enables progress block)'), ['size' => 15]);
    $this->add('text', 'campaign_title', E::ts('Campaign title (free text, shown in template)'), ['size' => 40]);
  }

  protected function getExtraValues(array $submitted): array {
    return [
      'period' => $submitted['period'] ?? 'yesterday',
      'campaign_id' => ($submitted['campaign_id'] ?? '') !== '' ? (int) $submitted['campaign_id'] : NULL,
      'financial_type_id' => ($submitted['financial_type_id'] ?? '') !== '' ? (int) $submitted['financial_type_id'] : NULL,
      'source_pattern' => $submitted['source_pattern'] ?? '',
      'show_pending' => !empty($submitted['show_pending']),
      'show_histogram' => !empty($submitted['show_histogram']),
      'bucket_count' => ($submitted['bucket_count'] ?? '') !== '' ? (int) $submitted['bucket_count'] : 8,
      'bucket_edges' => $submitted['bucket_edges'] ?? '',
      'goal' => ($submitted['goal'] ?? '') !== '' ? (float) $submitted['goal'] : NULL,
      'campaign_title' => $submitted['campaign_title'] ?? '',
    ];
  }

  protected function extraDefaults(): array {
    $params = $this->getActionParameters();
    return [
      'period' => $params['period'] ?? 'yesterday',
      'campaign_id' => $params['campaign_id'] ?? '',
      'financial_type_id' => $params['financial_type_id'] ?? '',
      'source_pattern' => $params['source_pattern'] ?? '',
      'show_pending' => $params['show_pending'] ?? TRUE,
      'show_histogram' => $params['show_histogram'] ?? TRUE,
      'bucket_count' => $params['bucket_count'] ?? 8,
      'bucket_edges' => $params['bucket_edges'] ?? '',
      'goal' => $params['goal'] ?? '',
      'campaign_title' => $params['campaign_title'] ?? '',
    ];
  }

}
