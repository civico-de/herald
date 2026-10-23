<?php

declare(strict_types = 1);

use Civi\Herald\Subscribers\LegacyPreferences;
use Civi\Herald\Subscribers\Subscriptions;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The one preference record per contact becomes a subscription row in
 * herald_subscription; the old group and its table go afterwards.
 */
final class CRM_Herald_Upgrader_SubscriptionRows {

  /**
   * One row per record with a channel, also for group messages, as the
   * record served both. Contacts that have rows already are left alone.
   *
   * @return array{rows: int, without_channel: int, unused: array<string, int>}
   *   Rows written, records with topics but no channel, and per recipient
   *   field the values the chosen channel never read.
   */
  public static function migrate(): array {
    $counts = ['rows' => 0, 'without_channel' => 0, 'unused' => []];
    if (!LegacyPreferences::pending()) {
      return $counts;
    }
    $types = CRM_Herald_Upgrader_Domain::channelTypes();
    $migrated = array_flip(array_map(
      Cast::toInt(...),
      \Civi\Api4\CustomValue::get(Subscriptions::GROUP, checkPermissions: FALSE)
        ->addSelect('entity_id')
        ->execute()
        ->column('entity_id'),
    ));
    $prefix = LegacyPreferences::GROUP . '.';
    $records = \Civi\Api4\Contact::get(checkPermissions: FALSE)
      ->addSelect('id', $prefix . '*')
      // Get skips the trash by default; a restored contact keeps its subscriptions.
      ->addWhere('is_deleted', 'IN', [FALSE, TRUE])
      ->addClause('OR', [$prefix . 'preferred_channel', 'IS NOT EMPTY'], [$prefix . 'subscriptions', 'IS NOT EMPTY'])
      ->execute();
    $rows = [];
    foreach ($records as $contact) {
      /** @var array<string, mixed> $contact */
      $contactId = Cast::toInt($contact['id']);
      $record = LegacyPreferences::record($contact);
      $channel = Cast::toString($record['preferred_channel'] ?? '');
      if (isset($migrated[$contactId])) {
        continue;
      }
      if ($channel === '') {
        $counts['without_channel']++;
        continue;
      }
      $type = $types[$channel] ?? NULL;
      foreach (self::unused($record, $type) as $field) {
        $counts['unused'][$field] = ($counts['unused'][$field] ?? 0) + 1;
      }
      $rows[] = self::row($contactId, $record, $type);
    }
    foreach (array_chunk($rows, 500) as $chunk) {
      \Civi\Api4\CustomValue::save(Subscriptions::GROUP, checkPermissions: FALSE)->setRecords($chunk)->execute();
    }
    $counts['rows'] = count($rows);
    return $counts;
  }

  /**
   * The record as a row that also takes group messages, as the record served both.
   *
   * @param array<string, mixed> $record
   * @return array<string, mixed>
   */
  private static function row(int $contactId, array $record, ?string $type): array {
    $row = LegacyPreferences::row($contactId, $record, $type);
    return [
      'entity_id' => $contactId,
      'channel' => $row['channel'],
      'recipient' => $row['recipient'],
      'topics' => is_array($record['subscriptions'] ?? NULL)
        ? array_values(array_filter($record['subscriptions'], is_string(...)))
        : [],
      'for_groups' => TRUE,
      'source_entity_type' => $row['source_entity_type'] !== '' ? $row['source_entity_type'] : NULL,
      'source_entity_id' => $row['source_entity_id'] > 0 ? $row['source_entity_id'] : NULL,
    ];
  }

  /**
   * The filled recipient fields that are not the channel type's own.
   *
   * @param array<string, mixed> $record
   * @return list<string>
   */
  private static function unused(array $record, ?string $type): array {
    $fields = array_diff_key(LegacyPreferences::RECIPIENT_FIELDS, [(string) $type => TRUE]);
    return array_values(array_filter(
      $fields,
      static fn (string $field): bool => trim(Cast::toString($record[$field] ?? '')) !== '',
    ));
  }

  /**
   * Drop the old group with its table, and first the profile fields naming
   * its fields, since core leaves them behind.
   */
  public static function retire(): void {
    $ids = \Civi\Api4\CustomField::get(checkPermissions: FALSE)
      ->addSelect('id')
      ->addWhere('custom_group_id:name', '=', LegacyPreferences::GROUP)
      ->execute()
      ->column('id');
    if ($ids !== []) {
      \Civi\Api4\UFField::delete(checkPermissions: FALSE)
        ->addWhere('field_name', 'IN', array_map(static fn (mixed $id): string => 'custom_' . Cast::toInt($id), $ids))
        ->execute();
    }
    \Civi\Api4\CustomGroup::delete(checkPermissions: FALSE)
      ->addWhere('name', '=', LegacyPreferences::GROUP)
      ->execute();
  }

}
