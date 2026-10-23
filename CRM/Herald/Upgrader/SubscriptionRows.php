<?php

declare(strict_types = 1);

use Civi\Herald\Subscribers\LegacyPreferences;
use Civi\Herald\Subscribers\Subscriptions;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The one preference record per contact becomes a subscription row in
 * herald_subscription and the contact's group channel, as the record served
 * both; the old group and its table go afterwards.
 *
 * @phpstan-import-type Subscription from Subscriptions
 */
final class CRM_Herald_Upgrader_SubscriptionRows {

  /**
   * A record with a channel becomes the group channel, and with topics also a
   * row. A contact that has rows, or a group channel, keeps them.
   *
   * @return array{rows: int, group_channels: int, without_channel: int, unused: array<string, int>}
   *   Rows and group channels written, records with topics but no channel,
   *   and per recipient field the values the chosen channel never read.
   */
  public static function migrate(): array {
    $counts = ['rows' => 0, 'group_channels' => 0, 'without_channel' => 0, 'unused' => []];
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
    $grouped = CRM_Herald_Upgrader_GroupChannels::present();
    $prefix = LegacyPreferences::GROUP . '.';
    $records = \Civi\Api4\Contact::get(checkPermissions: FALSE)
      ->addSelect('id', $prefix . '*')
      // Get skips the trash by default; a restored contact keeps its subscriptions.
      ->addWhere('is_deleted', 'IN', [FALSE, TRUE])
      ->addClause('OR', [$prefix . 'preferred_channel', 'IS NOT EMPTY'], [$prefix . 'subscriptions', 'IS NOT EMPTY'])
      ->execute();
    $rows = [];
    $groupChannels = [];
    foreach ($records as $contact) {
      /** @var array<string, mixed> $contact */
      $contactId = Cast::toInt($contact['id']);
      $record = LegacyPreferences::record($contact);
      $channel = Cast::toString($record['preferred_channel'] ?? '');
      if ($channel === '') {
        $counts['without_channel']++;
        continue;
      }
      $type = $types[$channel] ?? NULL;
      foreach (self::unused($record, $type) as $field) {
        $counts['unused'][$field] = ($counts['unused'][$field] ?? 0) + 1;
      }
      $row = LegacyPreferences::row($contactId, $record, $type);
      $topics = LegacyPreferences::topics($record);
      if ($topics !== [] && !isset($migrated[$contactId])) {
        $rows[] = self::row($row, $topics);
      }
      if (!isset($grouped[$contactId])) {
        $groupChannels[] = CRM_Herald_Upgrader_GroupChannels::record($row);
      }
    }
    foreach (array_chunk($rows, 500) as $chunk) {
      \Civi\Api4\CustomValue::save(Subscriptions::GROUP, checkPermissions: FALSE)->setRecords($chunk)->execute();
    }
    CRM_Herald_Upgrader_GroupChannels::save($groupChannels);
    $counts['rows'] = count($rows);
    $counts['group_channels'] = count($groupChannels);
    return $counts;
  }

  /**
   * @param Subscription $row
   * @param list<string> $topics
   * @return array<string, mixed>
   */
  private static function row(array $row, array $topics): array {
    return [
      'entity_id' => $row['contact_id'],
      'channel' => $row['channel'],
      'recipient' => $row['recipient'],
      'topics' => $topics,
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
