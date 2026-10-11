<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Api4\Contribution;
use Civi\Api4\Membership;
use Civi\Api4\Participant;
use Civi\Herald\Privacy\Mask;
use Civi\Herald\Util\StoredFields;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Template variables derived from a trigger's entity payload.
 *
 * The input is a plain context array — the CiviRules adapter unpacks the
 * trigger object into it, and nothing here knows CiviRules exists:
 *   contact_id     — int, 0 when the trigger carries no contact
 *   GroupContact   — entity payload of the "added to group" triggers
 *   Contribution, Membership, Participant, Event — entity payloads, when carried
 *   original       — pre-edit values keyed by entity name, when the trigger
 *                    recorded them (["Contribution" => ["contribution_status_id" => 2]])
 *
 * Lookups are defensive: a vanished contact/group just leaves the variable out
 * rather than failing the whole notification.
 * @mago-expect lint:cyclomatic-complexity
 */
final class ContextVars {

  private const CONTRIBUTION_FIELDS = [
    'contact_id',
    'total_amount',
    'currency',
    'contribution_status_id',
    'financial_type_id',
    'campaign_id',
    'source',
    'receive_date',
  ];

  private const MEMBERSHIP_FIELDS = ['contact_id', 'membership_type_id', 'status_id'];

  private const PARTICIPANT_FIELDS = ['contact_id', 'event_id', 'role_id', 'status_id'];

  /**
   * @param array<string, mixed> $context
   * @return array<string, mixed>
   */
  public static function build(array $context): array {
    $contribution = StoredFields::complete(
      Contribution::get(checkPermissions: FALSE),
      self::entity($context, 'Contribution'),
      self::CONTRIBUTION_FIELDS,
    );
    $membership = StoredFields::complete(
      Membership::get(checkPermissions: FALSE),
      self::entity($context, 'Membership'),
      self::MEMBERSHIP_FIELDS,
    );
    $participant = StoredFields::complete(
      Participant::get(checkPermissions: FALSE),
      self::entity($context, 'Participant'),
      self::PARTICIPANT_FIELDS,
    );
    $contactId = Cast::toInt($context['contact_id'] ?? 0);
    if ($contactId <= 0) {
      $contactId = Cast::toInt(
        $participant['contact_id'] ?? $membership['contact_id'] ?? $contribution['contact_id'] ?? 0,
      );
    }
    $vars = ContributionVars::forTrigger(
      $contribution,
      OriginalValues::field($context, 'Contribution', 'contribution_status_id'),
    );
    self::addContact($vars, $contactId);
    self::addGroup($vars, self::entity($context, 'GroupContact'));
    self::addMembership($vars, $membership);
    self::addParticipant($vars, $participant);
    // A bare Event entity (the "Event is added / changed / deleted" triggers).
    // The participant branch already derives $event from its event_id, so only
    // fill in here when the trigger carries an Event directly.
    self::addEvent($vars, self::entity($context, 'Event'));
    return $vars;
  }

  /**
   * @param array<string, mixed> $context
   * @return array<array-key, mixed>
   */
  private static function entity(array $context, string $key): array {
    $value = $context[$key] ?? NULL;
    return is_array($value) ? $value : [];
  }

  /**
   * @param array<string, mixed> $vars
   */
  private static function addContact(array &$vars, int $contactId): void {
    if ($contactId <= 0) {
      return;
    }
    try {
      $displayName = \CRM_Core_DAO::singleValueQuery(
        'SELECT display_name FROM civicrm_contact WHERE id = %1',
        [1 => [$contactId, 'Integer']],
      );
      $email = \CRM_Core_DAO::singleValueQuery(
        'SELECT email FROM civicrm_email WHERE contact_id = %1 ORDER BY is_primary DESC, id ASC LIMIT 1',
        [1 => [$contactId, 'Integer']],
      );
      $vars['contact'] = [
        'id' => $contactId,
        'display_name' => (string) $displayName,
        'email' => (string) $email,
        'masked_name' => Mask::words((string) $displayName),
        'masked_email' => Mask::email((string) $email),
      ];
    }
    catch (\Throwable $e) {
      \Civi::log()->error('herald: could not resolve trigger contact', [
        'contact_id' => $contactId,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * @param array<string, mixed> $vars
   * @param array<array-key, mixed> $groupContact
   */
  private static function addGroup(array &$vars, array $groupContact): void {
    $groupId = Cast::toInt($groupContact['group_id'] ?? 0);
    if ($groupId <= 0) {
      return;
    }
    try {
      $title = \CRM_Core_DAO::singleValueQuery(
        'SELECT title FROM civicrm_group WHERE id = %1',
        [1 => [$groupId, 'Integer']],
      );
      $vars['group'] = ['id' => $groupId, 'title' => (string) $title];
    }
    catch (\Throwable $e) {
      \Civi::log()->error('herald: could not resolve trigger group', [
        'group_id' => $groupId,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * @param array<string, mixed> $vars
   * @param array<array-key, mixed> $membership
   */
  private static function addMembership(array &$vars, array $membership): void {
    if (empty($membership['id'])) {
      return;
    }
    try {
      $vars['membership'] = [
        'membership_type' => self::labelOf(
          'civicrm_membership_type',
          'name',
          Cast::toInt($membership['membership_type_id'] ?? 0),
        ),
        'status' => self::labelOf('civicrm_membership_status', 'label', Cast::toInt($membership['status_id'] ?? 0)),
      ];
    }
    catch (\Throwable $e) {
      \Civi::log()->error('herald: could not resolve trigger membership', ['error' => $e->getMessage()]);
    }
  }

  /**
   * @param array<string, mixed> $vars
   * @param array<array-key, mixed> $participant
   */
  private static function addParticipant(array &$vars, array $participant): void {
    if (empty($participant['id'])) {
      return;
    }
    try {
      $vars['event'] = [
        'id' => Cast::toInt($participant['event_id'] ?? 0),
        'title' => self::labelOf('civicrm_event', 'title', Cast::toInt($participant['event_id'] ?? 0)),
      ];
      // Stored rows keep multiple roles as one separator-delimited string.
      $roleIds = $participant['role_id'] ?? '';
      $roleIds = is_array($roleIds) ? $roleIds : explode(\CRM_Core_DAO::VALUE_SEPARATOR, Cast::toString($roleIds));
      $roleIds = array_values(array_filter($roleIds, static fn (mixed $v): bool => Cast::toString($v) !== ''));
      $roleId = Cast::toString($roleIds[0] ?? '');
      $role = '';
      if ($roleId !== '') {
        $role = Cast::toString(\CRM_Core_DAO::singleValueQuery(
          "SELECT label FROM civicrm_option_value
           WHERE option_group_id = (SELECT id FROM civicrm_option_group WHERE name = 'participant_role')
             AND value = %1",
          [1 => [$roleId, 'String']],
        ));
      }
      $status = self::labelOf('civicrm_participant_status_type', 'label', Cast::toInt($participant['status_id'] ?? 0));
      $vars['participant'] = ['role' => $role, 'status' => $status];
    }
    catch (\Throwable $e) {
      \Civi::log()->error('herald: could not resolve trigger participant', ['error' => $e->getMessage()]);
    }
  }

  /**
   * @param array<string, mixed> $vars
   * @param array<array-key, mixed> $eventData
   */
  private static function addEvent(array &$vars, array $eventData): void {
    if (isset($vars['event']) || empty($eventData['id'])) {
      return;
    }
    try {
      $eventId = Cast::toInt($eventData['id']);
      // Default to the trigger's own snapshot: on a delete trigger the row is
      // already gone, so the live SELECT below finds nothing and the snapshot
      // is all we have for the human-readable bits.
      $title = Cast::toString($eventData['title'] ?? '');
      $start = Cast::toString($eventData['start_date'] ?? '');
      $isActive = isset($eventData['is_active']) ? Cast::toInt($eventData['is_active']) : NULL;
      // The object{} shape narrows executeQuery()'s CRM_Core_DAO|object union.
      /** @var \CRM_Core_DAO&object{title: ?string, start_date: ?string, is_active: ?string} $row */
      $row = \CRM_Core_DAO::executeQuery(
        'SELECT title, start_date, is_active FROM civicrm_event WHERE id = %1',
        [1 => [$eventId, 'Integer']],
      );
      if ($row->fetch()) {
        $title = Cast::toString($row->title);
        $start = Cast::toString($row->start_date);
        $isActive = Cast::toInt($row->is_active);
      }
      $vars['event'] = [
        'id' => $eventId,
        'title' => $title,
        'start_date' => $start,
        'is_active' => $isActive,
      ];
    }
    catch (\Throwable $e) {
      \Civi::log()->error('herald: could not resolve trigger event', ['error' => $e->getMessage()]);
    }
  }

  /**
   * Single-column lookup by primary key; '' when the row is gone.
   */
  private static function labelOf(string $table, string $column, int $id): string {
    if ($id <= 0) {
      return '';
    }
    return Cast::toString(\CRM_Core_DAO::singleValueQuery(
      "SELECT `{$column}` FROM `{$table}` WHERE id = %1",
      [1 => [$id, 'Integer']],
    ));
  }

  /**
   * Pure decode of the `vars` parameter. Accepts an array passthrough (forms
   * can store arrays) or a JSON-encoded string; anything else collapses to [].
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed> Template variable names to values.
   */
  public static function decodeVars(array $params): array {
    $raw = $params['vars'] ?? '';
    if (is_array($raw)) {
      return Cast::namedOnly($raw);
    }
    if (trim(Cast::toString($raw)) === '') {
      return [];
    }
    $decoded = json_decode(Cast::toString($raw), TRUE);
    return is_array($decoded) ? Cast::namedOnly($decoded) : [];
  }

}
