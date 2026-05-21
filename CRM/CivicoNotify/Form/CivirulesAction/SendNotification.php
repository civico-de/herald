<?php

use CRM_CivicoNotify_ExtensionUtil as E;

class CRM_CivicoNotify_Form_CivirulesAction_SendNotification extends CRM_CivicoNotify_Form_CivirulesAction_AbstractSendForm {

  protected function getTemplateOptions(): array {
    return $this->lookupCivicoTemplates();
  }

  protected function addExtraFields(): void {
    $this->add('textarea', 'vars', E::ts('Variables (JSON object passed to the template)'), [
      'rows' => 6,
      'cols' => 60,
      'placeholder' => '{"contact": {"display_name": "Foo Bar"}}',
    ]);
  }

  protected function getExtraValues(array $submitted): array {
    $raw = $submitted['vars'] ?? '';
    if (trim((string) $raw) === '') {
      return ['vars' => []];
    }
    $decoded = json_decode((string) $raw, TRUE);
    if (!is_array($decoded)) {
      throw new \CRM_Core_Exception(E::ts('Variables JSON is invalid: %1', [1 => json_last_error_msg()]));
    }
    return ['vars' => $decoded];
  }

  protected function extraDefaults(): array {
    $params = $this->getActionParameters();
    $vars = $params['vars'] ?? [];
    return ['vars' => is_array($vars) ? json_encode($vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $vars];
  }

  /**
   * @return array<string, string>
   */
  private function lookupCivicoTemplates(): array {
    $rows = \Civi\Api4\MessageTemplate::get(FALSE)
      ->addSelect('workflow_name', 'msg_title')
      ->addWhere('workflow_name', 'LIKE', 'civico_notify_%')
      ->addWhere('is_default', '=', TRUE)
      ->execute()
      ->getArrayCopy();
    $opts = [];
    foreach ($rows as $row) {
      $opts[$row['workflow_name']] = $row['msg_title'] ?: $row['workflow_name'];
    }
    return $opts;
  }

}
