<?php

namespace Civi\CivicoNotify\Subscribers;

/**
 * Resolves contacts subscribed to a given notification kind into a flat list
 * of dispatch instructions (which channel, with which channel_config).
 *
 * Why a separate service: keeps the API4 query in one place, lets us unit-test
 * the result-mapping (apv4 result rows → dispatch dicts) without spinning up
 * Civi for every test.
 */
class SubscriberLookup {

  /**
   * @param string $subscriptionType One of the values from the
   *   `civico_notify_subscription_type` option group (e.g. "completed_alert").
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public function findBySubscriptionType(string $subscriptionType): array {
    if ($subscriptionType === '') {
      return [];
    }

    $rows = \Civi\Api4\Contact::get(FALSE)
      ->addSelect(
        'id',
        'civico_notify_preferences.preferred_channel',
        'civico_notify_preferences.telegram_chat_id',
        'civico_notify_preferences.pushover_user_key',
        'civico_notify_preferences.ntfy_topic',
        'civico_notify_preferences.webhook_url',
        'civico_notify_preferences.subscriptions'
      )
      ->addWhere('is_deleted', '=', FALSE)
      ->addWhere('civico_notify_preferences.subscriptions', 'CONTAINS', $subscriptionType)
      ->execute()
      ->getArrayCopy();

    return self::mapRows($rows);
  }

  /**
   * Map raw APIv4 result rows to dispatch instructions. Pure — test-seam.
   *
   * Skips contacts with no preferred channel or no recipient ID for that
   * channel; the caller decides what to do with the empty list (typically
   * just no-op the send).
   *
   * @param array<int, array<string, mixed>> $rows
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public static function mapRows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
      $channel = (string) ($row['civico_notify_preferences.preferred_channel'] ?? '');
      if ($channel === '') {
        continue;
      }
      $config = self::buildConfigForChannel($channel, $row);
      if ($config === NULL) {
        continue;
      }
      $out[] = [
        'contact_id' => (int) ($row['id'] ?? 0),
        'channel' => $channel,
        'config' => $config,
      ];
    }
    return $out;
  }

  /**
   * Build the channel config dict for one contact row, or NULL if the
   * recipient ID required by their preferred channel is missing.
   *
   * @return array<string, mixed>|null
   */
  public static function buildConfigForChannel(string $channel, array $row): ?array {
    return match ($channel) {
      'telegram' => self::nonEmpty($row, 'civico_notify_preferences.telegram_chat_id', ['chat_id' => $row['civico_notify_preferences.telegram_chat_id'] ?? '']),
      'pushover' => self::nonEmpty($row, 'civico_notify_preferences.pushover_user_key', ['pushover_user_key' => $row['civico_notify_preferences.pushover_user_key'] ?? '']),
      'ntfy' => self::nonEmpty($row, 'civico_notify_preferences.ntfy_topic', ['ntfy_topic' => $row['civico_notify_preferences.ntfy_topic'] ?? '']),
      'webhook' => self::nonEmpty($row, 'civico_notify_preferences.webhook_url', ['webhook_url' => $row['civico_notify_preferences.webhook_url'] ?? '']),
      default => NULL,
    };
  }

  /** Returns $config if $row[$key] is non-empty, else NULL. */
  private static function nonEmpty(array $row, string $key, array $config): ?array {
    return !empty($row[$key]) ? $config : NULL;
  }

}
