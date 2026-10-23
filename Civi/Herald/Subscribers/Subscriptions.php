<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The subscription rows of living contacts, each one channel, one recipient,
 * topics and an optional scope. While the upgrade from one preference record
 * per contact is pending, that record is read as the contact's only row.
 *
 * @phpstan-type Subscription array{contact_id: int, channel: string, recipient: string, source_entity_type: string, source_entity_id: int}
 */
final class Subscriptions {

  public const GROUP = 'herald_subscription';

  /**
   * @return list<Subscription>
   */
  public static function ofTopic(string $topic): array {
    if (LegacyPreferences::pending()) {
      return LegacyPreferences::ofTopic($topic);
    }
    return self::rows(self::get()->addWhere('topics', 'CONTAINS', SubscribableTopics::pattern($topic)));
  }

  /**
   * The rows of these contacts that also take group messages.
   *
   * @param list<int> $contactIds
   * @return list<Subscription>
   */
  public static function forGroups(array $contactIds): array {
    if ($contactIds === []) {
      return [];
    }
    if (LegacyPreferences::pending()) {
      return LegacyPreferences::forGroups($contactIds);
    }
    return self::rows(
      self::get()->addWhere('entity_id', 'IN', $contactIds)->addWhere('for_groups', '=', value: TRUE),
    );
  }

  /**
   * How many contacts subscribed to the topic through at least one row,
   * counting the trash too: its rows come back with a restored contact.
   */
  public static function subscribers(string $topic): int {
    if (LegacyPreferences::pending()) {
      return LegacyPreferences::subscribers($topic);
    }
    $count = \Civi\Api4\CustomValue::get(self::GROUP, checkPermissions: FALSE)
      ->addSelect('COUNT(DISTINCT entity_id) AS contacts')
      ->addWhere('topics', 'CONTAINS', SubscribableTopics::pattern($topic))
      ->execute()
      ->first();
    return Cast::toInt($count['contacts'] ?? 0);
  }

  private static function get(): DAOGetAction {
    return self::living()
      ->addSelect(
        'entity_id',
        'channel',
        'recipient',
        'source_entity_type',
        'source_entity_id',
      );
  }

  private static function living(): DAOGetAction {
    return \Civi\Api4\CustomValue::get(self::GROUP, checkPermissions: FALSE)
      ->addWhere('entity_id.is_deleted', '=', value: FALSE)
      ->addWhere('entity_id.is_deceased', '=', value: FALSE);
  }

  /**
   * @return list<Subscription>
   */
  private static function rows(DAOGetAction $get): array {
    $rows = [];
    foreach ($get->execute() as $row) {
      /** @var array<string, mixed> $row */
      $rows[] = self::row(
        Cast::toInt($row['entity_id'] ?? 0),
        Cast::toString($row['channel'] ?? ''),
        Cast::toString($row['recipient'] ?? ''),
        Cast::toString($row['source_entity_type'] ?? ''),
        Cast::toInt($row['source_entity_id'] ?? 0),
      );
    }
    return $rows;
  }

  /**
   * @return Subscription
   */
  public static function row(
    int $contactId,
    string $channel,
    string $recipient,
    string $scopeType,
    int $scopeId,
  ): array {
    return [
      'contact_id' => $contactId,
      'channel' => $channel,
      'recipient' => trim($recipient),
      'source_entity_type' => $scopeType,
      'source_entity_id' => $scopeId,
    ];
  }

}
