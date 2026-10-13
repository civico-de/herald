<?php

declare(strict_types = 1);

namespace Civi\Herald\Schedule;

/**
 * Validates schedule values as stored in trigger params or submitted by a form.
 */
final class ScheduleParams {

  /**
   * @param array<array-key, mixed> $params weekdays, hour and since
   * @throws \CRM_Core_Exception naming the first invalid value
   */
  public static function parse(array $params): Schedule {
    $hour = $params['hour'] ?? '';
    if ($hour === '') {
      throw new \CRM_Core_Exception('herald: the schedule has no hour');
    }
    $weekdays = self::weekdays($params['weekdays'] ?? '');
    $since = $params['since'] ?? 0;
    $sinceTime = self::inRange($since, 0, PHP_INT_MAX) ?? throw new \CRM_Core_Exception(
      'herald: schedule since must be a Unix time, got ' . self::show($since),
    );
    return new Schedule($weekdays, self::hour($hour, 'schedule hour'), $sinceTime);
  }

  /**
   * An hour of the day from 0 to 23.
   *
   * @throws \CRM_Core_Exception naming $label and the value
   */
  public static function hour(mixed $value, string $label): int {
    return (
      self::inRange($value, 0, 23) ?? throw new \CRM_Core_Exception(
        "herald: {$label} must be an hour from 0 to 23, got " . self::show($value),
      )
    );
  }

  /**
   * @return list<int> sorted and unique; all seven days become the empty list
   * @throws \CRM_Core_Exception
   */
  private static function weekdays(mixed $weekdays): array {
    $weekdays = $weekdays === '' ? [] : $weekdays;
    if (!is_array($weekdays)) {
      throw new \CRM_Core_Exception('herald: schedule weekdays must be a list, got ' . self::show($weekdays));
    }
    $days = array_map(
      static fn (mixed $day): int => (
        self::inRange($day, 1, 7) ?? throw new \CRM_Core_Exception(
          'herald: a schedule weekday must be 1 (Monday) to 7 (Sunday), got ' . self::show($day),
        )
      ),
      array_values($weekdays),
    );
    $days = array_values(array_unique($days));
    sort($days);
    return count($days) === 7 ? [] : $days;
  }

  /**
   * An integer from $min to $max, given as an int or a decimal string.
   */
  private static function inRange(mixed $value, int $min, int $max): ?int {
    $range = ['options' => ['min_range' => $min, 'max_range' => $max]];
    $number = is_bool($value) ? FALSE : filter_var($value, FILTER_VALIDATE_INT, $range);
    return is_int($number) ? $number : NULL;
  }

  private static function show(mixed $value): string {
    return is_scalar($value) ? var_export($value, TRUE) : get_debug_type($value);
  }

}
