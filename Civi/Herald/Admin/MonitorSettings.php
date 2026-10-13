<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use CRM_Herald_ExtensionUtil as E;

/**
 * Validated save of the monitor's two admin-owned settings, the expected list
 * and the snooze map. The monitor state is never written here.
 */
final class MonitorSettings {

  /**
   * Replace both settings. An unchanged snooze keeps its stored string; a new
   * one must lie in the future and is stored as ISO 8601 with offset.
   *
   * @param array<array-key, mixed> $expected
   * @param array<array-key, mixed> $snooze Check name => datetime; '' unsnoozes.
   * @return array{expected: list<string>, snooze: array<string, string>}
   * @throws InvalidInput
   */
  public static function save(array $expected, array $snooze): array {
    $errors = [];
    $cleanExpected = [];
    foreach ($expected as $name) {
      if (!is_string($name) || $name === '' || trim($name) !== $name) {
        $errors[] = E::ts('Enter every expected check name without leading or trailing spaces.');
        continue;
      }
      $cleanExpected[] = $name;
    }

    $stored = StringMap::of(\Civi::settings()->get(MonitorChecks::SNOOZE));
    $cleanSnooze = [];
    foreach ($snooze as $name => $until) {
      if ($until === '' || $until === NULL) {
        continue;
      }
      [$value, $error] = self::snooze((string) $name, $until, $stored[$name] ?? NULL);
      if ($error !== NULL) {
        $errors[] = $error;
        continue;
      }
      $cleanSnooze[(string) $name] = $value;
    }
    InvalidInput::throwIfAny($errors);

    $cleanExpected = array_values(array_unique($cleanExpected));
    \Civi::settings()->set(MonitorChecks::EXPECTED, $cleanExpected);
    \Civi::settings()->set(MonitorChecks::SNOOZE, $cleanSnooze);
    return ['expected' => $cleanExpected, 'snooze' => $cleanSnooze];
  }

  /**
   * @return array{0: string, 1: ?string} The value to store, or an error.
   */
  private static function snooze(string $name, mixed $until, ?string $stored): array {
    $untilTs = is_string($until) ? strtotime($until) : FALSE;
    if ($untilTs === FALSE) {
      return ['', E::ts('The snooze of "%1" is not a date and time.', [1 => $name])];
    }
    if ($stored !== NULL && strtotime($stored) === $untilTs) {
      return [$stored, NULL];
    }
    if ($untilTs <= \CRM_Utils_Time::time()) {
      return ['', E::ts('The snooze of "%1" must end in the future.', [1 => $name])];
    }
    return [date('c', $untilTs), NULL];
  }

}
