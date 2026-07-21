<?php

namespace Civi\Herald\Subscribers;

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
   *   `herald_subscription_type` option group (e.g. "completed_alert").
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public function findBySubscriptionType(string $subscriptionType): array {
    if ($subscriptionType === '') {
      return [];
    }

    $rows = \Civi\Api4\Contact::get(FALSE)
      ->addSelect(
        'id',
        'herald_preferences.preferred_channel',
        'herald_preferences.telegram_chat_id',
        'herald_preferences.pushover_user_key',
        'herald_preferences.ntfy_topic',
        'herald_preferences.webhook_url',
        'herald_preferences.subscriptions',
        'email_primary.email',
        'do_not_email'
      )
      ->addWhere('is_deleted', '=', FALSE)
      ->addWhere('is_deceased', '=', FALSE)
      ->addWhere('herald_preferences.subscriptions', 'CONTAINS', $subscriptionType)
      ->execute()
      ->getArrayCopy();

    /** @var array<int, array<string, mixed>> $rows */
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
      $channel = self::str($row['herald_preferences.preferred_channel'] ?? '');
      if ($channel === '') {
        continue;
      }
      $config = self::buildConfigForChannel($channel, $row);
      if ($config === NULL) {
        continue;
      }
      $out[] = [
        'contact_id' => self::int($row['id'] ?? 0),
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
   * @param array<string, mixed> $row
   * @return array<string, mixed>|null
   */
  public static function buildConfigForChannel(string $channel, array $row): ?array {
    return match ($channel) {
      'telegram' => self::nonEmpty($row, 'herald_preferences.telegram_chat_id', ['chat_id' => $row['herald_preferences.telegram_chat_id'] ?? '']),
      'pushover' => self::nonEmpty($row, 'herald_preferences.pushover_user_key', ['pushover_user_key' => $row['herald_preferences.pushover_user_key'] ?? '']),
      'ntfy' => self::nonEmpty($row, 'herald_preferences.ntfy_topic', ['ntfy_topic' => $row['herald_preferences.ntfy_topic'] ?? '']),
      'webhook' => self::nonEmpty($row, 'herald_preferences.webhook_url', ['webhook_url' => $row['herald_preferences.webhook_url'] ?? '']),
      // Email needs no preference field — the contact's primary address is the
      // recipient. do_not_email is a hard privacy flag and trumps the opt-in.
      'email' => (!empty($row['email_primary.email']) && empty($row['do_not_email']))
        ? ['email_to' => $row['email_primary.email']]
        : NULL,
      default => NULL,
    };
  }

  /**
   * Returns $config if $row[$key] is non-empty, else NULL.
   *
   * @param array<string, mixed> $row
   * @param array<string, mixed> $config
   * @return array<string, mixed>|null
   */
  private static function nonEmpty(array $row, string $key, array $config): ?array {
    return !empty($row[$key]) ? $config : NULL;
  }

  /**
   * Narrow a mixed APIv4-row value to string. Guards the cast against the
   * theoretical mixed type without changing behaviour for well-formed data.
   */
  private static function str(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Narrow a mixed APIv4-row value to int. Same rationale as self::str().
   */
  private static function int(mixed $value): int {
    return is_scalar($value) ? (int) $value : 0;
  }

}
