<?php

/**
 * Generic CiviRules action: render any workflow template and send it through any channel.
 *
 * Use this when none of the contribution-specific actions fit. Static vars come
 * from the `vars` action param; on top of that the trigger's context is made
 * available to the template automatically: `$contact` (id + display_name) when
 * the trigger carries a contact, `$group` (id + title) when it carries a
 * GroupContact (e.g. the "Contact is added to Group" trigger), `$membership`,
 * `$participant` + `$event` (from a participant), and a bare `$event`
 * (id + title + start_date + is_active) when the trigger carries an Event
 * directly (the "Event is added / changed / deleted" triggers). Explicit keys
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
    $groupId = is_array($groupContact) ? self::asInt($groupContact['group_id'] ?? 0) : 0;
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
    $membership = $triggerData->getEntityData('Membership');
    if (is_array($membership) && !empty($membership['id'])) {
      try {
        $vars['membership'] = [
          'membership_type' => self::labelOf('civicrm_membership_type', 'name', self::asInt($membership['membership_type_id'] ?? 0)),
          'status' => self::labelOf('civicrm_membership_status', 'label', self::asInt($membership['status_id'] ?? 0)),
        ];
      }
      catch (\Throwable $e) {
        \Civi::log()->warning('herald: could not resolve trigger membership', ['error' => $e->getMessage()]);
      }
    }
    $participant = $triggerData->getEntityData('Participant');
    if (is_array($participant) && !empty($participant['id'])) {
      try {
        $vars['event'] = [
          'id' => self::asInt($participant['event_id'] ?? 0),
          'title' => self::labelOf('civicrm_event', 'title', self::asInt($participant['event_id'] ?? 0)),
        ];
        $roleId = $participant['role_id'] ?? '';
        $roleId = is_array($roleId) ? self::asString(reset($roleId)) : self::asString($roleId);
        $role = '';
        if ($roleId !== '') {
          $role = self::asString(\CRM_Core_DAO::singleValueQuery(
            "SELECT label FROM civicrm_option_value
             WHERE option_group_id = (SELECT id FROM civicrm_option_group WHERE name = 'participant_role')
               AND value = %1",
            [1 => [$roleId, 'String']]
          ));
        }
        $vars['participant'] = ['role' => $role];
      }
      catch (\Throwable $e) {
        \Civi::log()->warning('herald: could not resolve trigger participant', ['error' => $e->getMessage()]);
      }
    }
    // A bare Event entity (the "Event is added / changed / deleted" triggers).
    // The participant branch above already derives $event from its event_id, so
    // only fill in here when the trigger carries an Event directly.
    $eventData = $triggerData->getEntityData('Event');
    if (!isset($vars['event']) && is_array($eventData) && !empty($eventData['id'])) {
      try {
        $eventId = self::asInt($eventData['id']);
        // Default to the trigger's own snapshot: on a delete trigger the row is
        // already gone, so the live SELECT below finds nothing and the snapshot
        // is all we have for the human-readable bits.
        $title = self::asString($eventData['title'] ?? '');
        $start = self::asString($eventData['start_date'] ?? '');
        $isActive = isset($eventData['is_active']) ? self::asInt($eventData['is_active']) : NULL;
        // executeQuery() is documented as CRM_Core_DAO|object — the `object`
        // half exists only so IDEs stop flagging the result-row properties,
        // which the DAO sets dynamically. Spell the SELECT list out instead.
        /** @var \CRM_Core_DAO&object{title: ?string, start_date: ?string, is_active: ?string} $row */
        $row = \CRM_Core_DAO::executeQuery(
          'SELECT title, start_date, is_active FROM civicrm_event WHERE id = %1',
          [1 => [$eventId, 'Integer']]
        );
        if ($row->fetch()) {
          $title = self::asString($row->title);
          $start = self::asString($row->start_date);
          $isActive = self::asInt($row->is_active);
        }
        $vars['event'] = [
          'id' => $eventId,
          'title' => $title,
          'start_date' => $start,
          'is_active' => $isActive,
        ];
      }
      catch (\Throwable $e) {
        \Civi::log()->warning('herald: could not resolve trigger event', ['error' => $e->getMessage()]);
      }
    }
    return $vars;
  }

  /**
   * Single-column lookup by primary key; '' when the row is gone.
   */
  private static function labelOf(string $table, string $column, int $id): string {
    if ($id <= 0) {
      return '';
    }
    return self::asString(\CRM_Core_DAO::singleValueQuery(
      "SELECT `$column` FROM `$table` WHERE id = %1",
      [1 => [$id, 'Integer']]
    ));
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
