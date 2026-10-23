<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Resolves the members of a CiviCRM group into dispatch instructions.
 *
 * A member goes out through the channel they chose for messages to their
 * groups. A member without a usable one gets their own resolved address
 * through the contact email channel (ContactRows::emailChannel()).
 *
 * @phpstan-import-type Subscription from Subscriptions
 */
class GroupRecipients {

  /**
   * @param int $groupId Static or smart group id (APIv4 "groups" semantics).
   * @param array<string, mixed> $options respect_suspension (bool),
   *   location_type_id (int, 0 = primary).
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public function findByGroup(int $groupId, array $options = []): array {
    if ($groupId <= 0) {
      return [];
    }
    /** @var array<int, array<string, mixed>> $rows */
    $rows = ContactRows::query($options)->addWhere('groups', 'IN', [$groupId])->execute()->getArrayCopy();
    $members = array_values(array_unique(array_map(static fn (array $row): int => Cast::toInt(
      $row['id'] ?? 0,
    ), $rows)));
    $mapped = self::mapGroupRows($rows, Subscriptions::forGroups($members), $options);
    if ($mapped['skipped'] !== []) {
      \Civi::log()->info('herald: group fan-out skipped recipients', [
        'group_id' => $groupId,
        'skipped' => $mapped['skipped'],
      ]);
    }
    return $mapped['recipients'];
  }

  /**
   * Map group-member rows and their group channels to dispatch
   * instructions. Pure — test-seam.
   *
   * @param array<int, array<string, mixed>> $rows
   * @param list<Subscription> $subscriptions
   * @param array<string, mixed> $options
   * @param array<string, string>|null $types Channel name => type; default ContactRows::subscribable().
   * @return array{
   *   recipients: array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>,
   *   skipped: array<string, int>,
   * }
   */
  public static function mapGroupRows(
    array $rows,
    array $subscriptions,
    array $options = [],
    ?array $types = NULL,
    ?string $emailChannel = NULL,
  ): array {
    $types ??= ContactRows::subscribable();
    $emailChannel ??= ContactRows::emailChannel();
    $own = [];
    foreach ($subscriptions as $subscription) {
      $own[$subscription['contact_id']][] = $subscription;
    }
    $out = [];
    /** @var array<string, int> $skipped */
    $skipped = [];
    foreach (ContactRows::byId($rows) as $contactId => $contactRows) {
      $reached = self::subscribed($contactId, $own[$contactId] ?? [], $contactRows, $options, $types);
      if ($reached !== []) {
        array_push($out, ...$reached);
        continue;
      }
      // The location-email join can duplicate a member; deliverability is
      // judged across all of its rows.
      $delivery = EmailRecipient::of($contactRows, $options);
      $reason = match (TRUE) {
        $delivery['suspended'] => 'suspended',
        $delivery['address'] === '' => 'no_recipient',
        default => NULL,
      };
      if ($reason !== NULL) {
        $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
        continue;
      }
      $out[] = [
        'contact_id' => $contactId,
        'channel' => $emailChannel,
        'config' => ['email_to' => $delivery['address']],
      ];
    }
    return ['recipients' => ContactRows::distinct($out), 'skipped' => $skipped];
  }

  /**
   * The member's group channel if usable; an unusable one falls back to the
   * member's own address like a missing one.
   *
   * @param list<Subscription> $subscriptions
   * @param non-empty-list<array<string, mixed>> $contactRows
   * @param array<string, mixed> $options
   * @param array<string, string> $types
   * @return list<array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  private static function subscribed(
    int $contactId,
    array $subscriptions,
    array $contactRows,
    array $options,
    array $types,
  ): array {
    $out = [];
    foreach ($subscriptions as $subscription) {
      $type = $types[$subscription['channel']] ?? NULL;
      $config = $type === NULL ? NULL : SubscriptionConfig::of($type, $subscription, $contactRows, $options)[0];
      if ($config !== NULL) {
        $out[] = ['contact_id' => $contactId, 'channel' => $subscription['channel'], 'config' => $config];
      }
    }
    return $out;
  }

}
