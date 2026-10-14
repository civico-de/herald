<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The event and participant variables of a trigger. Each returns nothing
 * when the trigger carries no such entity.
 */
final class EventVars {

  /**
   * The participant's role and status, and its event.
   *
   * @param array<array-key, mixed> $participant
   * @return array<string, mixed>
   */
  public static function participant(array $participant): array {
    if (empty($participant['id'])) {
      return [];
    }
    $participant += ['event_id' => 0, 'role_id' => '', 'status_id' => 0];
    $eventId = Cast::toInt($participant['event_id']);
    return [
      'event' => ['id' => $eventId, 'title' => ContactVars::label('civicrm_event', 'title', $eventId)],
      'participant' => [
        'role' => self::role($participant['role_id']),
        'status' => ContactVars::label(
          'civicrm_participant_status_type',
          'label',
          Cast::toInt($participant['status_id']),
        ),
      ],
    ];
  }

  /**
   * The stored event, else the trigger's own snapshot: on a delete trigger
   * the row is already gone.
   *
   * @param array<array-key, mixed> $eventData
   * @return array<string, mixed>
   */
  public static function event(array $eventData): array {
    if (empty($eventData['id'])) {
      return [];
    }
    $eventId = Cast::toInt($eventData['id']);
    // The object{} shape narrows executeQuery()'s CRM_Core_DAO|object union.
    /** @var \CRM_Core_DAO&object{title: ?string, start_date: ?string, is_active: ?string} $row */
    $row = \CRM_Core_DAO::executeQuery(
      'SELECT title, start_date, is_active FROM civicrm_event WHERE id = %1',
      [1 => [$eventId, 'Integer']],
    );
    $stored = $row->fetch()
      ? ['title' => $row->title, 'start_date' => $row->start_date, 'is_active' => $row->is_active]
      : [];
    $event = $stored + $eventData + ['title' => '', 'start_date' => ''];
    return ['event' => [
      'id' => $eventId,
      'title' => Cast::toString($event['title']),
      'start_date' => Cast::toString($event['start_date']),
      'is_active' => isset($event['is_active']) ? Cast::toInt($event['is_active']) : NULL,
    ]];
  }

  /**
   * Label of the first role; stored rows keep several roles as one
   * separator-delimited string.
   */
  private static function role(mixed $roleIds): string {
    $roleIds = is_array($roleIds) ? $roleIds : explode(\CRM_Core_DAO::VALUE_SEPARATOR, Cast::toString($roleIds));
    $roleId = Cast::toString(
      array_values(array_filter($roleIds, static fn (mixed $v): bool => Cast::toString($v) !== ''))[0] ?? '',
    );
    if ($roleId === '') {
      return '';
    }
    return Cast::toString(\CRM_Core_DAO::singleValueQuery(
      "SELECT label FROM civicrm_option_value
       WHERE option_group_id = (SELECT id FROM civicrm_option_group WHERE name = 'participant_role')
         AND value = %1",
      [1 => [$roleId, 'String']],
    ));
  }

}
