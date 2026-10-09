<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Form for the digest action: either the fields of one contribution block or
 * a JSON list of blocks of any type.
 * @mago-expect lint:cyclomatic-complexity
 */
class CRM_Herald_CivirulesAdapter_Form_SendDigest extends CRM_Herald_CivirulesAdapter_Form_AbstractSend {

  protected function templateRequired(): bool {
    return FALSE;
  }

  /**
   * @return array<string, string>
   */
  protected function getTemplateOptions(): array {
    $rows = \Civi\Api4\MessageTemplate::get(checkPermissions: FALSE)
      ->addSelect('workflow_name', 'msg_title')
      ->addWhere('workflow_name', 'LIKE', 'herald_contribution_digest_%')
      ->addWhere('is_default', '=', value: TRUE)
      ->execute()
      ->getArrayCopy();
    $opts = ['' => E::ts('- block default -')];
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
    $this->add('textarea', 'blocks', E::ts('Blocks (JSON array; replaces the contribution fields below)'), [
      'rows' => 6,
      'cols' => 70,
      'placeholder' => '[{"type": "search", "search": "new_memberships_yesterday", "title": "Memberships"}]',
    ]);
    $this->add('text', 'header', E::ts('Header line (HTML, optional)'), ['size' => 50]);
    $this->addFormRule([self::class, 'validateBlocks']);
    $this->add(
      'select',
      'period',
      E::ts('Period'),
      [
        'yesterday' => E::ts('Yesterday'),
        'today' => E::ts('Today'),
        'last_7_days' => E::ts('Last 7 days'),
        'last_30_days' => E::ts('Last 30 days'),
      ],
      required: TRUE,
    );
    $this->add('text', 'campaign_id', E::ts('Campaign ID (optional)'), ['size' => 10]);
    $this->add('text', 'financial_type_id', E::ts('Financial type ID (optional)'), ['size' => 10]);
    $this->add('text', 'source_pattern', E::ts('Source LIKE pattern (optional, e.g. %Bank TX%)'), [
      'size' => 40,
    ]);
    $this->add('select', 'group_by', E::ts('Break down by'), [
      '' => E::ts('Contribution source'),
      'line_item' => E::ts('Line item and unit price'),
    ]);
    $this->heraldCheckbox('show_pending', E::ts('Show pending pool'));
    $this->heraldCheckbox('show_histogram', E::ts('Show histogram'));
    $this->add('text', 'bucket_count', E::ts('Histogram bucket count'), ['size' => 5]);
    $this->add('text', 'bucket_edges', E::ts('Histogram bucket edges (JSON array, optional)'), [
      'size' => 50,
      'placeholder' => '[0, 10, 50, 250, 5000]',
    ]);
  }

  /**
   * @param array<string, mixed> $submitted
   * @return array<string, mixed>
   */
  protected function getExtraValues(array $submitted): array {
    $campaignId = self::toStringValue($submitted['campaign_id'] ?? '');
    $financialTypeId = self::toStringValue($submitted['financial_type_id'] ?? '');
    $bucketCount = self::toStringValue($submitted['bucket_count'] ?? '');
    return [
      'blocks' => trim(self::toStringValue($submitted['blocks'] ?? '')),
      'header' => trim(self::toStringValue($submitted['header'] ?? '')),
      'period' => $submitted['period'] ?? 'yesterday',
      'campaign_id' => $campaignId !== '' ? (int) $campaignId : NULL,
      'financial_type_id' => $financialTypeId !== '' ? (int) $financialTypeId : NULL,
      'source_pattern' => $submitted['source_pattern'] ?? '',
      'group_by' => ($submitted['group_by'] ?? '') === 'line_item' ? 'line_item' : '',
      'show_pending' => !empty($submitted['show_pending']),
      'show_histogram' => !empty($submitted['show_histogram']),
      'bucket_count' => $bucketCount !== '' ? (int) $bucketCount : 8,
      'bucket_edges' => $submitted['bucket_edges'] ?? '',
    ];
  }

  /**
   * @return array<string, mixed>
   */
  protected function extraDefaults(): array {
    $params = $this->getActionParameters();
    return [
      'blocks' => $params['blocks'] ?? '',
      'header' => $params['header'] ?? '',
      'period' => $params['period'] ?? 'yesterday',
      'campaign_id' => $params['campaign_id'] ?? '',
      'financial_type_id' => $params['financial_type_id'] ?? '',
      'source_pattern' => $params['source_pattern'] ?? '',
      'group_by' => $params['group_by'] ?? '',
      'show_pending' => $params['show_pending'] ?? TRUE,
      'show_histogram' => $params['show_histogram'] ?? TRUE,
      'bucket_count' => $params['bucket_count'] ?? 8,
      'bucket_edges' => $params['bucket_edges'] ?? '',
    ];
  }

  /**
   * @param array<string, mixed> $values
   * @return array<string, string>|true
   */
  public static function validateBlocks(array $values): array|bool {
    $problems = \Civi\Herald\Digest\BlockList::problems(['blocks' => $values['blocks'] ?? '']);
    return $problems === [] ? TRUE : ['blocks' => implode(' ', $problems)];
  }

}
