<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_Civirules_Action')) {
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
     * Return the URL of the per-action parameter form. CiviRules' base form reads
     * rule_action_id; without it, find() loads the first rule action of any rule.
     */
    public function getExtraDataInputUrl($ruleActionId) {
      return $this->getFormattedExtraDataInputUrl($this->getFormPath(), (int) $ruleActionId);
    }

    /**
     * Civi route of the parameter form. Override in each concrete subclass.
     */
    abstract protected function getFormPath(): string;

    /**
     * Where the action sends to, for CiviRules' list of the rule's actions.
     */
    protected function deliveryText(): string {
      $params = $this->getActionParameters();
      $topic = Cast::toString($params['topic'] ?? '');
      if ($topic !== '') {
        return E::ts('Sends via topic "%1".', [1 => $topic]);
      }
      $channel = Cast::toString($params['channel'] ?? '');
      $mode = Cast::toString($params['delivery_mode'] ?? 'direct');
      return (
        in_array($mode, ['subscribers', 'group'], TRUE)
          ? E::ts('Sends to each contact on their own channel.')
          : E::ts('Sends through the channel "%1".', [1 => $channel])
      );
    }

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
}
