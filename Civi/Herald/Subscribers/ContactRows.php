<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The contact query and row mapping that subscriber and group fan-outs share.
 */
final class ContactRows {

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
        'herald_preferences.preferred_channel',
        'herald_preferences.telegram_chat_id',
        'herald_preferences.pushover_user_key',
        'herald_preferences.ntfy_topic',
        'herald_preferences.webhook_url',
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
   * Bucket join-duplicated rows per contact, keeping row order. Rows without
   * a contact id each form their own bucket.
   *
   * @param array<int, array<string, mixed>> $rows
   * @return array<int, non-empty-array<int, array<string, mixed>>>
   */
  public static function byContact(array $rows): array {
    $grouped = [];
    $anonymous = [];
    foreach ($rows as $row) {
      $contactId = Cast::toInt($row['id'] ?? 0);
      if ($contactId <= 0) {
        $anonymous[] = [$row];
        continue;
      }
      $grouped[$contactId][] = $row;
    }
    return array_merge(array_values($grouped), $anonymous);
  }

  /**
   * Config for a contact's non-email preferred channel, or NULL when the
   * recipient field that channel needs is empty or the channel is unknown.
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>|null
   */
  public static function channelConfig(string $channel, array $row): ?array {
    [$field, $key] = match ($channel) {
      'telegram' => ['telegram_chat_id', 'chat_id'],
      'pushover' => ['pushover_user_key', 'pushover_user_key'],
      'ntfy' => ['ntfy_topic', 'ntfy_topic'],
      'webhook' => ['webhook_url', 'webhook_url'],
      default => ['', ''],
    };
    $value = $field === '' ? NULL : $row['herald_preferences.' . $field] ?? NULL;
    return empty($value) ? NULL : [$key => $value];
  }

}
