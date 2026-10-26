<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Row shapes of the monitor screen: snooze entries and monitor state columns.
 */
final class MonitorRows {

  /**
   * @param array<string, string> $snooze
   * @return list<array{pattern: string, until: string, until_ts: ?int}>
   */
  public static function snooze(array $snooze): array {
    $rows = [];
    foreach ($snooze as $pattern => $until) {
      $ts = $until !== '' ? strtotime($until) : FALSE;
      $rows[] = ['pattern' => $pattern, 'until' => $until, 'until_ts' => $ts !== FALSE ? $ts : NULL];
    }
    return $rows;
  }

  /**
   * @param array<array-key, mixed>|null $entry
   * @return array{status: string, consecutive_fails: int, consecutive_oks: int, notified: bool, last_notified: ?int}
   */
  public static function state(?array $entry): array {
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

}
