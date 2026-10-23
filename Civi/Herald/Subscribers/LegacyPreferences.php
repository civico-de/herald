<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The one preference record per contact of an older release (custom group
 * herald_preferences), read as a subscription row until the upgrade has
 * moved it into herald_subscription and dropped the group.
 *
 * @phpstan-import-type Subscription from Subscriptions
 */
final class LegacyPreferences {

  public const GROUP = 'herald_preferences';

  /**
   * Channel type => the field holding the recipient; an email channel used
   * the contact's own address.
   */
  public const RECIPIENT_FIELDS = [
    'telegram' => 'telegram_chat_id',
    'pushover' => 'pushover_user_key',
    'ntfy' => 'ntfy_topic',
    'webhook' => 'webhook_url',
  ];

  public static function pending(): bool {
    $count = \CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM civicrm_custom_group WHERE name = %1',
      [1 => [self::GROUP, 'String']],
    );
    return Cast::toInt($count) > 0;
  }

  /**
   * @return list<Subscription>
   */
  public static function ofTopic(string $topic): array {
    return self::rows(
      self::get()->addWhere(self::GROUP . '.subscriptions', 'CONTAINS', SubscribableTopics::pattern($topic)),
    );
  }

  /**
   * Every record with a channel served messages to the contact's groups.
   *
   * @param list<int> $contactIds
   * @return list<Subscription>
   */
  public static function forGroups(array $contactIds): array {
    return self::rows(
      self::get()
        ->addWhere('id', 'IN', $contactIds)
        ->addWhere(self::GROUP . '.preferred_channel', 'IS NOT EMPTY'),
    );
  }

  public static function subscribers(string $topic): int {
    return \Civi\Api4\Contact::get(checkPermissions: FALSE)
      ->selectRowCount()
      ->addWhere(self::GROUP . '.subscriptions', 'CONTAINS', SubscribableTopics::pattern($topic))
      ->execute()
      ->countMatched();
  }

  /**
   * One record as a subscription row; the recipient comes from the field of
   * the channel's type, other recipient fields were never read.
   *
   * @param array<string, mixed> $record Field name => value
   * @return Subscription
   */
  public static function row(int $contactId, array $record, ?string $type): array {
    $field = self::RECIPIENT_FIELDS[$type ?? ''] ?? NULL;
    return Subscriptions::row(
      $contactId,
      Cast::toString($record['preferred_channel'] ?? ''),
      $field === NULL ? '' : Cast::toString($record[$field] ?? ''),
      Cast::toString($record['source_entity_type'] ?? ''),
      Cast::toInt($record['source_entity_id'] ?? 0),
    );
  }

  /**
   * @param array<string, mixed> $record
   * @return list<string>
   */
  public static function topics(array $record): array {
    $topics = $record['subscriptions'] ?? NULL;
    return is_array($topics) ? array_values(array_filter($topics, is_string(...))) : [];
  }

  private static function get(): DAOGetAction {
    return \Civi\Api4\Contact::get(checkPermissions: FALSE)
      ->addSelect('id', self::GROUP . '.*')
      ->addWhere('is_deleted', '=', value: FALSE)
      ->addWhere('is_deceased', '=', value: FALSE);
  }

  /**
   * @return list<Subscription>
   */
  private static function rows(DAOGetAction $get): array {
    $types = ContactRows::subscribable();
    $rows = [];
    foreach ($get->execute() as $contact) {
      /** @var array<string, mixed> $contact */
      $record = self::record($contact);
      $channel = Cast::toString($record['preferred_channel'] ?? '');
      $rows[] = self::row(Cast::toInt($contact['id'] ?? 0), $record, $types[$channel] ?? NULL);
    }
    return $rows;
  }

  /**
   * The record's fields of a Contact.get row, by field name.
   *
   * @param array<string, mixed> $contact
   * @return array<string, mixed>
   */
  public static function record(array $contact): array {
    $prefix = self::GROUP . '.';
    $record = [];
    foreach ($contact as $key => $value) {
      if (!str_starts_with($key, $prefix)) {
        continue;
      }
      $record[substr($key, strlen($prefix))] = $value;
    }
    return $record;
  }

}
