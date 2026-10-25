<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * What one contact submitted on a "Notifications" form must satisfy: the
 * group channel and each subscription row as SubscriptionRules has it.
 */
final class NotificationsRules {

  private const JOIN = 'Custom_' . Subscriptions::GROUP;

  /**
   * Messages naming the part of the form they belong to.
   *
   * @param array<string, mixed> $record Submitted `fields` and `joins` of one contact
   * @param array<string, string> $types Channel name => type
   * @return list<string>
   */
  public static function problems(array $record, array $types): array {
    $fields = self::named($record['fields'] ?? NULL);
    $group = [];
    foreach (['channel', 'recipient'] as $name) {
      $key = Subscriptions::GROUP_DELIVERY . '.' . $name;
      if (array_key_exists($key, $fields)) {
        $group[$name] = [$name, $fields[$key]];
      }
    }
    $problems = [];
    foreach (SubscriptionRules::errors($group, $types) as $message) {
      $problems[] = E::ts('Messages to groups: %1', [1 => $message]);
    }
    $rows = array_values((array) (self::named($record['joins'] ?? NULL)[self::JOIN] ?? []));
    foreach ($rows as $i => $row) {
      $values = [];
      foreach (self::named($row) as $name => $value) {
        $values[$name] = [$name, $value];
      }
      foreach (SubscriptionRules::errors($values, $types) as $message) {
        $problems[] = E::ts('Subscription %1: %2', [1 => $i + 1, 2 => $message]);
      }
    }
    return $problems;
  }

  /**
   * @return array<string, mixed>
   */
  private static function named(mixed $values): array {
    return is_array($values) ? Cast::namedOnly($values) : [];
  }

}
