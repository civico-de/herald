<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Api4\Event;
use Civi\Api4\OptionValue;
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
      'event' => ['id' => $eventId, 'title' => ContactVars::label('Event', 'title', $eventId)],
      'participant' => [
        'role' => self::role($participant['role_id']),
        'status' => ContactVars::label(
          'ParticipantStatusType',
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
    // By id, so event templates are found too.
    $stored = Event::get(checkPermissions: FALSE)
      ->addSelect('title', 'start_date', 'is_active')
      ->addWhere('id', '=', $eventId)
      ->execute()
      ->first() ?? [];
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
    return Cast::toString(
      OptionValue::get(checkPermissions: FALSE)
        ->addSelect('label')
        ->addWhere('option_group_id:name', '=', 'participant_role')
        ->addWhere('value', '=', $roleId)
        ->execute()
        ->first()['label'] ?? '',
    );
  }

}
