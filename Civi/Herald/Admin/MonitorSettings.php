<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Herald\Monitor\KeyPattern;
use Civi\Herald\Monitor\Threshold;
use CRM_Herald_ExtensionUtil as E;

/**
 * Validated save of the monitor's admin-owned settings: threshold, threshold
 * exceptions, snoozes and expected extensions. The monitor state is never written here.
 */
final class MonitorSettings {

  public const EXTENSION_KEY = '/^[A-Za-z0-9_.-]+$/';

  /**
   * Replace all four settings. An unchanged dated snooze keeps its stored
   * string; a new one must lie in the future and is stored as ISO 8601 with offset.
   *
   * @param array<array-key, mixed> $severity Key pattern => level.
   * @param array<array-key, mixed> $snooze Key pattern => datetime, '' for no end.
   * @param array<array-key, mixed> $expected Extension keys.
   * @return array{min_severity: string, severity: array<string, string>, snooze: array<string, string>, expected: list<string>}
   * @throws InvalidInput
   */
  public static function save(mixed $minSeverity, array $severity, array $snooze, array $expected): array {
    $errors = array_filter([Threshold::minProblem($minSeverity), ...Threshold::exceptionsProblems($severity)]);
    [$cleanSnooze, $snoozeErrors] = self::snoozes($snooze);
    [$cleanExpected, $expectedErrors] = self::expected($expected);
    InvalidInput::throwIfAny(array_values([...$errors, ...$snoozeErrors, ...$expectedErrors]));

    /** @var string $minSeverity */
    /** @var array<string, string> $severity */
    \Civi::settings()->set(Threshold::MIN_SETTING, $minSeverity);
    \Civi::settings()->set(Threshold::EXCEPTIONS_SETTING, $severity);
    \Civi::settings()->set(MonitorChecks::SNOOZE, $cleanSnooze);
    \Civi::settings()->set(MonitorChecks::EXPECTED, $cleanExpected);
    return [
      'min_severity' => $minSeverity,
      'severity' => $severity,
      'snooze' => $cleanSnooze,
      'expected' => $cleanExpected,
    ];
  }

  /**
   * @param array<array-key, mixed> $snooze
   * @return array{0: array<string, string>, 1: list<string>}
   */
  private static function snoozes(array $snooze): array {
    $stored = StringMap::of(\Civi::settings()->get(MonitorChecks::SNOOZE));
    $clean = [];
    $errors = [];
    foreach ($snooze as $pattern => $until) {
      $pattern = (string) $pattern;
      if (!KeyPattern::isValid($pattern)) {
        $errors[] = E::ts('"%1" is not a check key or a prefix ending in *.', [1 => $pattern]);
        continue;
      }
      [$value, $error] = self::until($pattern, $until, $stored[$pattern] ?? NULL);
      if ($error !== NULL) {
        $errors[] = $error;
        continue;
      }
      $clean[$pattern] = $value;
    }
    return [$clean, $errors];
  }

  /**
   * @return array{0: string, 1: ?string} The value to store, or an error.
   */
  private static function until(string $pattern, mixed $until, ?string $stored): array {
    if ($until === '' || $until === NULL) {
      return ['', NULL];
    }
    $untilTs = is_string($until) ? strtotime($until) : FALSE;
    if ($untilTs === FALSE) {
      return ['', E::ts('The snooze of "%1" is not a date and time.', [1 => $pattern])];
    }
    if ($stored !== NULL && $stored !== '' && strtotime($stored) === $untilTs) {
      return [$stored, NULL];
    }
    if ($untilTs <= \CRM_Utils_Time::time()) {
      return ['', E::ts('The snooze of "%1" must end in the future.', [1 => $pattern])];
    }
    return [date('c', $untilTs), NULL];
  }

  /**
   * @param array<array-key, mixed> $expected
   * @return array{0: list<string>, 1: list<string>}
   */
  private static function expected(array $expected): array {
    $clean = [];
    $errors = [];
    foreach ($expected as $key) {
      if (!is_string($key) || preg_match(self::EXTENSION_KEY, $key) !== 1) {
        $errors[] = E::ts('Enter every expected extension by its key, such as org.example.myext, without spaces.');
        continue;
      }
      $clean[] = $key;
    }
    return [array_values(array_unique($clean)), $errors];
  }

}
