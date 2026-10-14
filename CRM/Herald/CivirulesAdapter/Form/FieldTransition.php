<?php

declare(strict_types = 1);

use Civi\Herald\Condition\EntityField;
use Civi\Herald\Condition\FieldTransition;
use Civi\Herald\Condition\FieldTransitionParams;
use Civi\Herald\Condition\FieldTransitionWatchList;
use CRM_Herald_ExtensionUtil as E;

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_CivirulesConditions_Form_Form')) {
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
      $operators =
        ['' => E::ts('- any value -')] + array_combine(FieldTransition::OPERATORS, FieldTransition::OPERATORS);
      $this->add('select', 'from_operator', E::ts('Before: operator'), $operators, required: FALSE);
      $this->add('text', 'from_value', E::ts('Before: value'), ['size' => 30]);
      $this->add('select', 'to_operator', E::ts('After: operator'), $operators, required: FALSE);
      $this->add('text', 'to_value', E::ts('After: value'), ['size' => 30]);
      $this->addFormRule($this->errorsIn(...));
      $this->addButtons([
        ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
        ['type' => 'cancel', 'name' => E::ts('Cancel')],
      ]);
    }

    /**
     * A misspelt entity or field, or a value an operator cannot compare, would leave the condition silently false.
     *
     * @param array<string, mixed> $values
     * @return array<string, string>|true
     */
    private function errorsIn(array $values): array|bool {
      $params = FieldTransitionParams::decode($values);
      return (
        FieldTransitionParams::sideErrors($params) + EntityField::errors($params['entity'], $params['field']) ?: TRUE
      );
    }

    /**
     * @return array<string, mixed>
     */
    public function setDefaultValues() {
      /** @var array<string, mixed> $defaults */
      $defaults = parent::setDefaultValues();
      return array_merge($defaults, FieldTransitionParams::decode($this->ruleCondition->condition_params ?? NULL));
    }

    public function postProcess(): void {
      $this->ruleCondition->condition_params = serialize(FieldTransitionParams::decode($this->exportValues()));
      $this->ruleCondition->save();
      // The pre-hook snapshot watch list is request-cached; drop it so a rule
      // saved and triggered in one process sees the fresh configuration.
      FieldTransitionWatchList::reset();
      \CRM_Core_Session::setStatus(E::ts('herald condition saved.'), '', 'success');
      parent::postProcess();
    }

  }
}
