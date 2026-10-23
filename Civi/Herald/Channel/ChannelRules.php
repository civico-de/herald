<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Subscribers\SubscriptionConfig;
use CRM_Herald_ExtensionUtil as E;

/**
 * What a channel record must satisfy before ChannelStore keeps it: a free
 * name, a type that does not change and one contacts can receive through.
 */
final class ChannelRules {

  /**
   * @param array<array-key, mixed> $record
   * @param array<string, array{type: string}> $all The stored channels
   * @return list<string>
   */
  public static function newRecordErrors(string $name, string $type, array $record, array $all): array {
    return self::recordErrors($name, $type, $record, self::duplicateError($name, $all[$name] ?? NULL));
  }

  /**
   * @param array<array-key, mixed> $record
   * @param array<string, array{type: string}> $all The stored channels
   * @return list<string>
   */
  public static function replacementErrors(string $name, string $type, array $record, array $all): array {
    return self::recordErrors($name, $type, $record, self::replaceError($name, $type, $all[$name] ?? NULL));
  }

  /**
   * @param array<array-key, mixed> $record
   * @return list<string>
   */
  private static function recordErrors(string $name, string $type, array $record, ?string $existence): array {
    $errors = array_filter([
      preg_match(ChannelStore::NAME_PATTERN, $name) === 1
        ? NULL
        : E::ts('A channel name starts with a lowercase letter and holds only a-z, 0-9 and _.'),
      $existence,
      empty($record['subscribable']) || SubscriptionConfig::reachable($type)
        ? NULL
        : E::ts('Contacts cannot receive through a channel of type "%1".', [1 => $type]),
    ]);
    return array_values($errors);
  }

  /**
   * @param array{type: string}|null $stored
   */
  private static function duplicateError(string $name, ?array $stored): ?string {
    return $stored === NULL ? NULL : E::ts('A channel named "%1" already exists.', [1 => $name]);
  }

  /**
   * @param array{type: string}|null $stored
   */
  private static function replaceError(string $name, string $type, ?array $stored): ?string {
    if ($stored === NULL) {
      return E::ts('The channel "%1" no longer exists. Reload the page.', [1 => $name]);
    }
    return $stored['type'] === $type ? NULL : E::ts('The type of a channel cannot change; add a new channel instead.');
  }

}
