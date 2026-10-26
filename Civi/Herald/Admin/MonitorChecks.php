<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Herald\Monitor\CheckCollector;
use Civi\Herald\Monitor\ExpectedExtensions;
use Civi\Herald\Monitor\Monitor;
use Civi\Herald\Monitor\Severity;
use Civi\Herald\Monitor\Snooze;
use Civi\Herald\Monitor\Threshold;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The monitor screen: the keys System.check reports and the keys in the
 * monitor state, plus the threshold, snooze and expected settings. Runs
 * System.check and writes nothing; message texts are not returned.
 */
final class MonitorChecks {

  public const EXPECTED = ExpectedExtensions::SETTING;

  public const SNOOZE = Snooze::SETTING;

  /**
   * Times are Unix timestamps so the browser shows them in its own time zone.
   *
   * @return array<string, mixed>
   */
  public static function screen(?CheckCollector $collector = NULL): array {
    $threshold = Threshold::fromSettings();
    $observations = ($collector ?? new CheckCollector())->observe($threshold);
    $state = self::array(Monitor::STATE_SETTING);
    $now = \CRM_Utils_Time::time();
    $snooze = new Snooze(Snooze::live(self::array(self::SNOOZE), $now), $now);

    $keys = array_values(array_unique(array_map('strval', [...array_keys($observations), ...array_keys($state)])));
    natcasesort($keys);
    $checks = [];
    foreach ($keys as $key) {
      $obs = $observations[$key] ?? NULL;
      $checks[] =
        [
          'key' => $key,
          'reported' => $obs !== NULL,
          'title' => $obs['title'] ?? '',
          'detail' => implode(', ', $obs['detail'] ?? []),
          'severity' => $obs !== NULL ? Severity::name($obs['severity']) : '',
          'severity_label' => $obs !== NULL ? Severity::label($obs['severity']) : '',
          'visible' => $obs['visible'] ?? NULL,
          'failing' => ($obs['status'] ?? 'ok') === 'fail',
          'threshold' => Severity::name($threshold->minFor($key)),
          'snoozed' => $snooze->covers($key),
        ] + MonitorRows::state(is_array($state[$key] ?? NULL) ? $state[$key] : NULL);
    }

    return [
      'checks' => $checks,
      'min_severity' => Cast::toString(\Civi::settings()->get(Threshold::MIN_SETTING)),
      'severity' => StringMap::of(\Civi::settings()->get(Threshold::EXCEPTIONS_SETTING)),
      'snooze' => MonitorRows::snooze(StringMap::of(self::array(self::SNOOZE))),
      'expected' => array_values(array_filter(self::array(self::EXPECTED), 'is_string')),
      'levels' => array_map(
        static fn (string $name, string $label): array => ['name' => $name, 'label' => $label],
        array_keys(Severity::thresholdOptions()),
        Severity::thresholdOptions(),
      ),
      'status_url' => \CRM_Utils_System::url('civicrm/a/', '', FALSE, '/status'),
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
