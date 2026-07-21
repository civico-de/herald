<?php

use CRM_Herald_ExtensionUtil as E;

class CRM_Herald_Form_CivirulesCondition_PreviousContributionStatus extends CRM_CivirulesConditions_Form_Form {

  public function buildQuickForm() {
    $this->add('select', 'previous_status_id', E::ts('Previous Contribution Status'), $this->getStatusOptions(), TRUE);
    $this->addButtons([
      ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  public function setDefaultValues() {
    $defaults = parent::setDefaultValues();
    $params = $this->getConditionParameters();
    if (isset($params['previous_status_id'])) {
      $defaults['previous_status_id'] = $params['previous_status_id'];
    }
    return $defaults;
  }

  public function postProcess() {
    $values = $this->exportValues();
    $params = ['previous_status_id' => (int) ($values['previous_status_id'] ?? 0)];
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
      $opts[(int) $row['value']] = $row['label'];
    }
    return $opts;
  }

  protected function getConditionParameters(): array {
    $raw = $this->ruleCondition->condition_params ?? '';
    if (!$raw) {
      return [];
    }
    $params = unserialize($raw, ['allowed_classes' => FALSE]);
    return is_array($params) ? $params : [];
  }

}
