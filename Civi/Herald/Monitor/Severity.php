<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Core's check severities (PSR-3 levels, ids 0–7) and the ones a monitor threshold may use.
 */
final class Severity {

  /**
   * Info and debug mark healthy states and cannot be hushed in core.
   */
  public const LOWEST_THRESHOLD = 'notice';

  /**
   * @return list<string> Level names in ascending order; the index is core's severity_id.
   */
  public static function names(): array {
    return array_values(array_map(Cast::toString(...), \CRM_Utils_Check::getSeverityList()));
  }

  public static function id(string $name): ?int {
    $id = array_search($name, self::names(), TRUE);
    return is_int($id) ? $id : NULL;
  }

  public static function name(int $id): string {
    return self::names()[$id] ?? '';
  }

  /**
   * @return list<string> The level names a threshold accepts, lowest first.
   */
  public static function thresholds(): array {
    return array_slice(self::names(), (int) self::id(self::LOWEST_THRESHOLD));
  }

  /**
   * Options for the threshold select on the settings page.
   *
   * @return array<string, string>
   */
  public static function thresholdOptions(): array {
    $labels = array_column(\CRM_Utils_Check::getSeverityOptions(), 'label', 'name');
    $options = [];
    foreach (self::thresholds() as $name) {
      $options[$name] = Cast::toString($labels[$name] ?? $name);
    }
    return $options;
  }

  public static function label(int $id): string {
    $labels = array_column(\CRM_Utils_Check::getSeverityOptions(), 'label', 'id');
    return Cast::toString($labels[$id] ?? self::name($id));
  }

}
