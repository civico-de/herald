<?php

declare(strict_types = 1);

namespace Civi\Herald\Schedule;

use CRM_Herald_ExtensionUtil as E;

/**
 * How a schedule reads in the CiviRules screens.
 */
final class ScheduleText {

  /**
   * "Daily at 08:00", "Every Monday and Thursday at 08:00".
   */
  public static function describe(Schedule $schedule): string {
    $time = sprintf('%02d:00', $schedule->hour);
    if ($schedule->weekdays === []) {
      return E::ts('Daily at %1', [1 => $time]);
    }
    $all = self::weekdayNames();
    $names = array_map(static fn (int $day): string => $all[$day], $schedule->weekdays);
    $last = array_pop($names);
    $days = $names === [] ? $last : E::ts('%1 and %2', [1 => implode(', ', $names), 2 => $last]);
    return E::ts('Every %1 at %2', [1 => $days, 2 => $time]);
  }

  /**
   * The description of stored params, or why they do not form a schedule.
   *
   * @param array<array-key, mixed> $params
   */
  public static function summary(array $params): string {
    try {
      return self::describe(ScheduleParams::parse($params));
    }
    catch (\CRM_Core_Exception $e) {
      return E::ts('Not configured: %1', [1 => $e->getMessage()]);
    }
  }

  /**
   * Localised weekday names keyed by ISO number, Monday first.
   *
   * @return array<int, string>
   */
  public static function weekdayNames(): array {
    // Core keys them 0 = Sunday … 6 = Saturday.
    $core = \CRM_Utils_Date::getFullWeekdayNames();
    $names = [];
    foreach (range(1, 7) as $iso) {
      $name = $core[$iso % 7] ?? NULL;
      if (!is_string($name)) {
        throw new \CRM_Core_Exception("herald: CiviCRM has no name for weekday {$iso}");
      }
      $names[$iso] = $name;
    }
    return $names;
  }

}
