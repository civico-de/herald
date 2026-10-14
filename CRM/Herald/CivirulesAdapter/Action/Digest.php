<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_Civirules_Action')) {
  /**
   * CiviRules adapter: send a digest. It needs no trigger entity — the digest
   * name and delivery settings come from the stored action parameters, so this
   * only forwards them to Herald.notifyDigest.
   */
  class CRM_Herald_CivirulesAdapter_Action_Digest extends CRM_Herald_CivirulesAdapter_Action_AbstractSend {

    protected function getFormPath(): string {
      return 'civicrm/herald/form/civirulesaction/senddigest';
    }

    /**
     * The digest's title in CiviRules' list of the rule's actions.
     */
    public function userFriendlyConditionParams(): string {
      $name = \Civi\Herald\Vendor\Civico\Stdlib\Cast::toString($this->getActionParameters()['digest'] ?? '');
      $title =
        \Civi\Api4\HeraldDigest::get(checkPermissions: FALSE)
          ->addSelect('title')
          ->addWhere('name', '=', $name)
          ->execute()
          ->first()['title'] ?? NULL;
      $text = $title === NULL
        ? E::ts('Digest "%1" does not exist.', [1 => $name])
        : E::ts('Sends the digest "%1".', [1 => $title]);
      return htmlspecialchars($text, ENT_QUOTES);
    }

    public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
      // CiviRules evaluates rules in a system context (cron, post-commit
      // queue) — there is no acting user whose ACLs could apply.
      $params = $this->getActionParameters();
      $digest = \Civi\Herald\Vendor\Civico\Stdlib\Cast::toString($params['digest'] ?? '');
      unset($params['digest']);
      \Civi\Api4\Herald::notifyDigest(checkPermissions: FALSE)
        ->setDigest($digest)
        ->setActionParams($params)
        ->execute();
    }

  }
}
