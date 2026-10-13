<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Herald\Monitor\CheckCollector;
use Civi\Herald\Monitor\Monitor;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The monitor's checks for the admin screen. The state setting is only read,
 * and never its detail text.
 */
final class MonitorChecks {

  public const EXPECTED = 'herald_monitor_expected';

  public const SNOOZE = 'herald_monitor_snooze';

  /**
   * Every check that is provided, expected, snoozed or has state; times are
   * Unix timestamps so the browser shows them in its own time zone.
   *
   * @return list<array<string, mixed>>
   */
  public static function rows(): array {
    $provided = (new CheckCollector())->names();
    $expected = array_values(array_filter(self::array(self::EXPECTED), 'is_string'));
    $snooze = StringMap::of(self::array(self::SNOOZE));
    $state = self::array(Monitor::STATE_SETTING);
    $now = \CRM_Utils_Time::time();

    $names = array_merge($provided, $expected, array_keys($snooze), array_keys($state));
    $names = array_values(array_unique(array_map('strval', $names)));
    natcasesort($names);

    $rows = [];
    foreach ($names as $name) {
      $until = $snooze[$name] ?? '';
      $untilTs = $until !== '' ? strtotime($until) : FALSE;
      $rows[] =
        [
          'name' => $name,
          'provided' => in_array($name, $provided, TRUE),
          'expected' => in_array($name, $expected, TRUE),
          'snoozed' => $untilTs !== FALSE && $untilTs > $now,
          'snoozed_until' => $until,
          'snoozed_until_ts' => $untilTs !== FALSE ? $untilTs : NULL,
        ] + self::stateColumns(is_array($state[$name] ?? NULL) ? $state[$name] : NULL);
    }
    return $rows;
  }

  /**
   * @param array<array-key, mixed>|null $entry
   * @return array{status: string, consecutive_fails: int, consecutive_oks: int, notified: bool, last_notified: ?int}
   */
  private static function stateColumns(?array $entry): array {
    if ($entry === NULL) {
      return [
        'status' => '',
        'consecutive_fails' => 0,
        'consecutive_oks' => 0,
        'notified' => FALSE,
        'last_notified' => NULL,
      ];
    }
    return [
      'status' => ($entry['status'] ?? 'ok') === 'fail' ? 'fail' : 'ok',
      'consecutive_fails' => max(0, Cast::toInt($entry['consecutive_fails'] ?? 0)),
      'consecutive_oks' => max(0, Cast::toInt($entry['consecutive_oks'] ?? 0)),
      'notified' => !empty($entry['notified']),
      'last_notified' => is_numeric($entry['last_notified'] ?? NULL) ? (int) $entry['last_notified'] : NULL,
    ];
  }

  /**
   * @return array<array-key, mixed>
   */
  private static function array(string $setting): array {
    $value = \Civi::settings()->get($setting);
    return is_array($value) ? $value : [];
  }

}
