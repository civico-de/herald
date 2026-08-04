<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

class CRM_Herald_CivirulesAdapter_Form_PreviousContributionStatus extends CRM_CivirulesConditions_Form_Form {

  public function buildQuickForm(): void {
    $this->add('select', 'previous_status_id', E::ts('Previous Contribution Status'), $this->getStatusOptions(), TRUE);
    $this->addButtons([
      ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function setDefaultValues() {
    /** @var array<string, mixed> $defaults */
    $defaults = parent::setDefaultValues();
    $params = $this->getConditionParameters();
    if (isset($params['previous_status_id'])) {
      $defaults['previous_status_id'] = $params['previous_status_id'];
    }
    return $defaults;
  }

  public function postProcess(): void {
    $values = $this->exportValues();
    $previousStatusId = $values['previous_status_id'] ?? 0;
    $params = ['previous_status_id' => is_numeric($previousStatusId) ? (int) $previousStatusId : 0];
    $this->ruleCondition->condition_params = serialize($params);
    $this->ruleCondition->save();
    \CRM_Core_Session::setStatus(E::ts('herald condition saved.'), '', 'success');
    parent::postProcess();
  }

  /**
   * @return array<int, string>
   */
  private function getStatusOptions(): array {
    $rows = \Civi\Api4\OptionValue::get(FALSE)
      ->addSelect('value', 'label')
      ->addWhere('option_group_id:name', '=', 'contribution_status')
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->getArrayCopy();
    $opts = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $value = $row['value'] ?? NULL;
      if (!is_numeric($value)) {
        continue;
      }
      $label = $row['label'] ?? NULL;
      $opts[(int) $value] = is_string($label) ? $label : (string) $value;
    }
    return $opts;
  }

  /**
   * @return array<array-key, mixed>
   */
  protected function getConditionParameters(): array {
    $raw = $this->ruleCondition->condition_params ?? '';
    if (!$raw) {
      return [];
    }
    $params = unserialize($raw, ['allowed_classes' => FALSE]);
    return is_array($params) ? $params : [];
  }

}
