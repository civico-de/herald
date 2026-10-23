<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The contact query and row mapping that subscriber and group fan-outs share.
 */
final class ContactRows {

  public const EMAIL_CHANNEL_SETTING = 'herald_contact_email_channel';

  /**
   * The channels a subscription can name: name => type.
   *
   * @return array<string, string>
   */
  public static function subscribable(?ChannelStore $store = NULL): array {
    $records = array_filter(
      ($store ?? new ChannelStore())->all(),
      static fn (array $record): bool => $record['active'] && $record['subscribable'],
    );
    return array_map(static fn (array $record): string => $record['type'], $records);
  }

  /**
   * The email channel a group member's own address is reached through when
   * the member has no usable subscription for group messages.
   */
  public static function emailChannel(): string {
    $name = \Civi::settings()->get(self::EMAIL_CHANNEL_SETTING);
    return is_string($name) && $name !== '' ? $name : 'email';
  }

  /**
   * Living contacts with the fields a recipient is resolved from. With a
   * location_type_id option, that location's addresses are joined in, one
   * row per address.
   *
   * @param array<string, mixed> $options
   */
  public static function query(array $options): DAOGetAction {
    $query = \Civi\Api4\Contact::get(checkPermissions: FALSE)
      ->addSelect(
        'id',
        'email_primary.email',
        'email_primary.on_hold',
        'do_not_email',
        'is_opt_out',
      )
      ->addWhere('is_deleted', '=', value: FALSE)
      ->addWhere('is_deceased', '=', value: FALSE);
    $locationTypeId = Cast::toInt($options['location_type_id'] ?? 0);
    if ($locationTypeId > 0) {
      $query->addJoin(
        'Email AS herald_loc_email',
        'LEFT',
        NULL,
        ['herald_loc_email.contact_id', '=', 'id'],
        ['herald_loc_email.location_type_id', '=', $locationTypeId],
      );
      $query->addSelect('herald_loc_email.email', 'herald_loc_email.on_hold');
    }
    return $query;
  }

  /**
   * The query rows of these contacts; none for no ids.
   *
   * @param list<int> $contactIds
   * @param array<string, mixed> $options
   * @return array<int, array<string, mixed>>
   */
  public static function of(array $contactIds, array $options): array {
    if ($contactIds === []) {
      return [];
    }
    /** @var array<int, array<string, mixed>> */
    return self::query($options)
      ->addWhere('id', 'IN', array_values(array_unique($contactIds)))
      ->execute()
      ->getArrayCopy();
  }

  /**
   * Query rows by contact id, keeping row order.
   *
   * @param array<int, array<string, mixed>> $rows
   * @return array<int, non-empty-list<array<string, mixed>>>
   */
  public static function byId(array $rows): array {
    $grouped = [];
    foreach ($rows as $row) {
      $grouped[Cast::toInt($row['id'] ?? 0)][] = $row;
    }
    return $grouped;
  }

  /**
   * Recipients without repeats: one send per contact, channel and config.
   *
   * @param array<int, array{contact_id: int, channel: string, config: array<string, mixed>}> $recipients
   * @return list<array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public static function distinct(array $recipients): array {
    $seen = [];
    foreach ($recipients as $recipient) {
      $seen[serialize($recipient)] ??= $recipient;
    }
    return array_values($seen);
  }

}
