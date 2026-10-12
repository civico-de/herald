<?php

declare(strict_types = 1);

use Civi\Herald\Condition\FieldTransition;
use Civi\Herald\Condition\FieldTransitionWatchList;
use CRM_Herald_ExtensionUtil as E;

/**
 * Parameter form for the generic field-transition condition: entity, field,
 * and an optional (operator, value) pair for each side of the transition.
 * CiviRules opens its parameter screens as QuickForm forms by route.
 * @mago-expect analysis:civikitchen/legacy-ui-base
 */
class CRM_Herald_CivirulesAdapter_Form_FieldTransition extends CRM_CivirulesConditions_Form_Form {

  public function buildQuickForm(): void {
    $this->add('text', 'entity', E::ts('Entity (APIv4 name, e.g. Contribution)'), ['size' => 30], required: TRUE);
    $this->add('text', 'field', E::ts('Field (APIv4 field name)'), ['size' => 30], required: TRUE);
    $operators = ['' => E::ts('- any value -')] + array_combine(FieldTransition::OPERATORS, FieldTransition::OPERATORS);
    $this->add('select', 'from_operator', E::ts('Before: operator'), $operators, required: FALSE);
    $this->add('text', 'from_value', E::ts('Before: value'), ['size' => 30]);
    $this->add('select', 'to_operator', E::ts('After: operator'), $operators, required: FALSE);
    $this->add('text', 'to_value', E::ts('After: value'), ['size' => 30]);
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
    foreach (['entity', 'field', 'from_operator', 'from_value', 'to_operator', 'to_value'] as $key) {
      if (!array_key_exists($key, $params)) {
        continue;
      }
      $defaults[$key] = $params[$key];
    }
    return $defaults;
  }

  public function postProcess(): void {
    $values = $this->exportValues();
    $params = [];
    foreach (['entity', 'field', 'from_operator', 'from_value', 'to_operator', 'to_value'] as $key) {
      $value = $values[$key] ?? '';
      $params[$key] = is_scalar($value) ? trim((string) $value) : '';
    }
    $this->ruleCondition->condition_params = serialize($params);
    $this->ruleCondition->save();
    // The pre-hook snapshot watch list is request-cached; drop it so a rule
    // saved and triggered in one process sees the fresh configuration.
    FieldTransitionWatchList::reset();
    \CRM_Core_Session::setStatus(E::ts('herald condition saved.'), '', 'success');
    parent::postProcess();
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
