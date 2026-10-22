<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Resolves the contacts subscribed to a topic into recipients,
 * each through the channel their preference names. A preference naming no
 * active subscribable channel counts as no_channel.
 */
class SubscriberLookup {

  /**
   * @param array<string, mixed> $options respect_suspension (bool),
   *   location_type_id (int, 0 = primary), scope (see SubscriptionScope).
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public function findByTopic(string $topic, array $options = []): array {
    if ($topic === '') {
      return [];
    }
    /** @var array<int, array<string, mixed>> $rows */
    $rows = ContactRows::query($options)
      ->addSelect(
        'herald_preferences.subscriptions',
        'herald_preferences.source_entity_type',
        'herald_preferences.source_entity_id',
      )
      ->addWhere('herald_preferences.subscriptions', 'CONTAINS', SubscribableTopics::pattern($topic))
      ->execute()
      ->getArrayCopy();
    $mapped = self::mapRowsDetailed($rows, $options);
    if ($mapped['skipped'] !== []) {
      \Civi::log()->info('herald: subscriber fan-out skipped recipients', [
        'topic' => $topic,
        'skipped' => $mapped['skipped'],
      ]);
    }
    return $mapped['recipients'];
  }

  /**
   * @param array<int, array<string, mixed>> $rows
   * @param array<string, mixed> $options
   * @param array<string, string>|null $types Channel name => type; default ContactRows::subscribable().
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public static function mapRows(array $rows, array $options = [], ?array $types = NULL): array {
    return self::mapRowsDetailed($rows, $options, $types)['recipients'];
  }

  /**
   * Recipients from query rows, and per reason how many contacts were left
   * out: scope_mismatch, suspended, no_channel, no_recipient.
   *
   * @param array<int, array<string, mixed>> $rows
   * @param array<string, mixed> $options
   * @param array<string, string>|null $types Channel name => type; default ContactRows::subscribable().
   * @return array{
   *   recipients: array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>,
   *   skipped: array<string, int>,
   * }
   */
  public static function mapRowsDetailed(array $rows, array $options = [], ?array $types = NULL): array {
    $types ??= ContactRows::subscribable();
    $scope = $options['scope'] ?? NULL;
    /** @var array{entity_type: string, entity_id: int}|null $scope */
    $scope = is_array($scope) ? $scope : NULL;
    $out = [];
    $skipped = [];
    foreach (ContactRows::byContact($rows) as $contactRows) {
      // Preference and scope fields are the same on every row of a contact.
      $row = $contactRows[0];
      $channel = Cast::toString($row['herald_preferences.preferred_channel'] ?? '');
      $reason = match (TRUE) {
        !SubscriptionScope::rowMatches($row, $scope) => 'scope_mismatch',
        !isset($types[$channel]) => 'no_channel',
        default => NULL,
      };
      $config = NULL;
      if ($reason === NULL) {
        [$config, $reason] = self::config($types[$channel], $contactRows, $options);
      }
      if ($config === NULL) {
        $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
        continue;
      }
      $out[] = ['contact_id' => Cast::toInt($row['id'] ?? 0), 'channel' => $channel, 'config' => $config];
    }
    return ['recipients' => $out, 'skipped' => $skipped];
  }

  /**
   * @param non-empty-array<int, array<string, mixed>> $contactRows
   * @param array<string, mixed> $options
   * @return array{0: array<string, mixed>|null, 1: string}
   *   The config, or NULL and the reason it is missing.
   */
  private static function config(string $type, array $contactRows, array $options): array {
    if ($type !== 'email') {
      return [ContactRows::channelConfig($type, $contactRows[0]), 'no_recipient'];
    }
    $email = EmailRecipient::of($contactRows, $options);
    if ($email['address'] === '') {
      return [NULL, $email['suspended'] ? 'suspended' : 'no_recipient'];
    }
    return [['email_to' => $email['address']], ''];
  }

}
