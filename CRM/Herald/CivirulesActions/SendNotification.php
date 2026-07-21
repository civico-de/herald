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
class CRM_Herald_CivirulesActions_SendNotification extends CRM_Herald_CivirulesActions_AbstractSend {

  protected function getFormPath(): string {
    return 'civicrm/herald/form/civirulesaction/sendnotification';
  }

  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
    $this->dispatch(self::decodeVars($this->getActionParameters()));
  }

  /**
   * Pure decode of the `vars` action param. Test-seam.
   *
   * Accepts an array passthrough (CiviRules form classes can store arrays) or
   * a JSON-encoded string. Anything else collapses to [].
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed> Template variable names to values.
   */
  public static function decodeVars(array $params): array {
    $raw = $params['vars'] ?? '';
    if (is_array($raw)) {
      return self::namedOnly($raw);
    }
    if (trim(self::asString($raw)) === '') {
      return [];
    }
    $decoded = json_decode(self::asString($raw), TRUE);
    return is_array($decoded) ? self::namedOnly($decoded) : [];
  }

}
