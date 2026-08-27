<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

class CRM_Herald_CivirulesAdapter_Form_SendNotification extends CRM_Herald_CivirulesAdapter_Form_AbstractSend {

  protected function getTemplateOptions(): array {
    return $this->lookupHeraldTemplates();
  }

  protected function supportsScopeEntity(): bool {
    return TRUE;
  }

  protected function addExtraFields(): void {
    $this->add('textarea', 'vars', E::ts('Variables (JSON object passed to the template)'), [
      'rows' => 6,
      'cols' => 60,
      'placeholder' => '{"contact": {"display_name": "Foo Bar"}}',
    ]);
  }

  /**
   * @param array<string, mixed> $submitted
   * @return array<string, mixed>
   */
  protected function getExtraValues(array $submitted): array {
    $raw = self::toStringValue($submitted['vars'] ?? '');
    if (trim($raw) === '') {
      return ['vars' => []];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      throw new \CRM_Core_Exception(E::ts('Variables JSON is invalid: %1', [1 => json_last_error_msg()]));
    }
    return ['vars' => $decoded];
  }

  /**
   * @return array<string, mixed>
   */
  protected function extraDefaults(): array {
    $params = $this->getActionParameters();
    $vars = $params['vars'] ?? [];
    $encoded = is_array($vars)
      ? json_encode($vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
      : self::toStringValue($vars);
    return ['vars' => $encoded];
  }

  /**
   * @return array<string, string>
   */
  private function lookupHeraldTemplates(): array {
    $rows = \Civi\Api4\MessageTemplate::get(checkPermissions: FALSE)
      ->addSelect('workflow_name', 'msg_title')
      ->addWhere('workflow_name', 'LIKE', 'herald_%')
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

}
