<?php

declare(strict_types = 1);

/**
 * Base for herald's CiviRules action adapters.
 *
 * Adapters carry no domain logic: they unpack CiviRules' TriggerData into
 * plain values and call a Herald.* APIv4 action. Everything that decides what
 * is rendered, buffered or sent lives in Civi\Herald\* behind that API.
 *
 * CiviRules instantiates handlers by class name from civirule_action.class_name
 * and checks `instanceof CRM_Civirules_Action`, so inheriting from its base
 * class is the only way to register — see the ignoreErrors entry in
 * phpstan.neon.dist.
 */
abstract class CRM_Herald_CivirulesAdapter_Action_AbstractSend extends CRM_Civirules_Action {

  /**
   * Return the URL of the per-action parameter form. Subclasses point to their
   * concrete CRM_Herald_CivirulesAdapter_Form_* route.
   */
  public function getExtraDataInputUrl($ruleActionId) {
    return \CRM_Utils_System::url($this->getFormPath(), 'reset=1&action=update&id=' . $ruleActionId);
  }

  /**
   * Civi route of the parameter form. Override in each concrete subclass.
   */
  abstract protected function getFormPath(): string;

  /**
   * Action parameters as CiviRules hands them over.
   *
   * CiviRules stores them serialised in civirule_rule_action.action_params, so
   * everything that comes back out is untyped. The receiving services coerce;
   * this only guarantees string keys.
   *
   * @return array<string, mixed>
   */
  protected function getActionParameters() {
    $params = [];
    foreach (parent::getActionParameters() as $key => $value) {
      $params[(string) $key] = $value;
    }
    return $params;
  }

}
