<?php

/**
 * Generic CiviRules action: render any workflow template and send it through any channel.
 *
 * Use this when none of the contribution-specific actions fit. Static vars come
 * from the `vars` action param; on top of that the trigger's context is made
 * available to the template automatically: `$contact` (id + display_name) when
 * the trigger carries a contact, `$group` (id + title) when it carries a
 * GroupContact (e.g. the "Contact is added to Group" trigger). Explicit keys
 * in `vars` win over the auto-filled ones.
 *
 * Action parameters (in addition to those from AbstractSend):
 *   vars — JSON object passed verbatim to the template renderer
 */
class CRM_Herald_CivirulesActions_SendNotification extends CRM_Herald_CivirulesActions_AbstractSend {

  protected function getFormPath(): string {
    return 'civicrm/herald/form/civirulesaction/sendnotification';
  }

  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
    $this->dispatch(self::decodeVars($this->getActionParameters()) + $this->buildTriggerVars($triggerData));
  }

  /**
   * Context vars derived from the trigger entity data. Lookups are defensive:
   * a vanished contact/group just leaves the var out rather than failing the
   * whole notification.
   *
   * @return array<string, mixed>
   */
  protected function buildTriggerVars(CRM_Civirules_TriggerData_TriggerData $triggerData): array {
    $vars = [];
    $contactId = (int) $triggerData->getContactId();
    if ($contactId > 0) {
      try {
        $displayName = \CRM_Core_DAO::singleValueQuery(
          'SELECT display_name FROM civicrm_contact WHERE id = %1',
          [1 => [$contactId, 'Integer']]
        );
        $email = \CRM_Core_DAO::singleValueQuery(
          'SELECT email FROM civicrm_email WHERE contact_id = %1 ORDER BY is_primary DESC, id ASC LIMIT 1',
          [1 => [$contactId, 'Integer']]
        );
        $vars['contact'] = [
          'id' => $contactId,
          'display_name' => (string) $displayName,
          'email' => (string) $email,
          'masked_name' => \Civi\Herald\Privacy\Mask::words((string) $displayName),
          'masked_email' => \Civi\Herald\Privacy\Mask::email((string) $email),
        ];
      }
      catch (\Throwable $e) {
        \Civi::log()->warning('herald: could not resolve trigger contact', ['contact_id' => $contactId, 'error' => $e->getMessage()]);
      }
    }
    $groupContact = $triggerData->getEntityData('GroupContact');
    $groupId = is_array($groupContact) ? (int) ($groupContact['group_id'] ?? 0) : 0;
    if ($groupId > 0) {
      try {
        $title = \CRM_Core_DAO::singleValueQuery(
          'SELECT title FROM civicrm_group WHERE id = %1',
          [1 => [$groupId, 'Integer']]
        );
        $vars['group'] = ['id' => $groupId, 'title' => (string) $title];
      }
      catch (\Throwable $e) {
        \Civi::log()->warning('herald: could not resolve trigger group', ['group_id' => $groupId, 'error' => $e->getMessage()]);
      }
    }
    return $vars;
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
