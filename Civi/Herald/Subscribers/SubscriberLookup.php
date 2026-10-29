<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

/**
 * Resolves the subscriptions to a topic into recipients, each through the
 * channel its row names. A row naming no active subscribable channel counts
 * as no_channel; two rows reaching a contact the same way send once.
 *
 * @phpstan-import-type Subscription from Subscriptions
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
    $subscriptions = Subscriptions::ofTopic($topic);
    $mapped = self::mapRowsDetailed(
      $subscriptions,
      ContactRows::of(array_column($subscriptions, 'contact_id'), $options),
      $options,
    );
    if ($mapped['skipped'] !== []) {
      \Civi::log()->info('herald: subscriber fan-out skipped recipients', [
        'topic' => $topic,
        'skipped' => $mapped['skipped'],
      ]);
    }
    return $mapped['recipients'];
  }

  /**
   * @param list<Subscription> $subscriptions
   * @param array<int, array<string, mixed>> $contactRows
   * @param array<string, mixed> $options
   * @param array<string, string>|null $types Channel name => type; default ContactRows::subscribable().
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public static function mapRows(
    array $subscriptions,
    array $contactRows,
    array $options = [],
    ?array $types = NULL,
  ): array {
    return self::mapRowsDetailed($subscriptions, $contactRows, $options, $types)['recipients'];
  }

  /**
   * Recipients from subscriptions and the rows of their contacts, and per
   * reason how many subscriptions were left out: scope_mismatch, suspended,
   * no_channel, no_recipient.
   *
   * @param list<Subscription> $subscriptions
   * @param array<int, array<string, mixed>> $contactRows ContactRows::query() rows
   * @param array<string, mixed> $options
   * @param array<string, string>|null $types Channel name => type; default ContactRows::subscribable().
   * @return array{
   *   recipients: array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>,
   *   skipped: array<string, int>,
   * }
   */
  public static function mapRowsDetailed(
    array $subscriptions,
    array $contactRows,
    array $options = [],
    ?array $types = NULL,
  ): array {
    $types ??= ContactRows::subscribable();
    $scope = $options['scope'] ?? NULL;
    /** @var array{entity_type: string, entity_id: int}|null $scope */
    $scope = is_array($scope) ? $scope : NULL;
    $contacts = ContactRows::byId($contactRows);
    $out = [];
    $skipped = [];
    foreach ($subscriptions as $subscription) {
      $channel = $subscription['channel'];
      $rows = $contacts[$subscription['contact_id']] ?? NULL;
      $reason = match (TRUE) {
        !SubscriptionScope::rowMatches($subscription, $scope) => 'scope_mismatch',
        !isset($types[$channel]) => 'no_channel',
        $rows === NULL => 'no_recipient',
        default => NULL,
      };
      [$config, $reason] =
        $reason === NULL && $rows !== NULL
          ? SubscriptionConfig::of($types[$channel], $subscription, $rows, $options)
          : [NULL, $reason];
      if ($config === NULL) {
        $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
        continue;
      }
      $out[] = ['contact_id' => $subscription['contact_id'], 'channel' => $channel, 'config' => $config];
    }
    return ['recipients' => ContactRows::distinct($out), 'skipped' => $skipped];
  }

}
