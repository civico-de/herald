<?php

/**
 * Generic CiviRules action: render any workflow template and send it through any channel.
 *
 * Use this when none of the contribution-specific actions fit. Caller must pass
 * the entire variable bag in `vars` (JSON-encoded action param).
 *
 * Action parameters (in addition to those from AbstractSend):
 *   vars — JSON object passed verbatim to the template renderer
 */
class CRM_CivicoNotify_CivirulesActions_SendNotification extends CRM_CivicoNotify_CivirulesActions_AbstractSend {

  protected function getFormPath(): string {
    return 'civicrm/civicoNotify/form/civirulesaction/sendnotification';
  }

  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $params = $this->getActionParameters();
    $vars = [];
    if (!empty($params['vars'])) {
      $decoded = is_array($params['vars']) ? $params['vars'] : json_decode((string) $params['vars'], TRUE);
      if (is_array($decoded)) {
        $vars = $decoded;
      }
    }
    $this->dispatch($vars);
  }

}
