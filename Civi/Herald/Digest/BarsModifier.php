<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use CRM_Herald_ExtensionUtil as E;

/**
 * The Smarty modifier herald_bars: one bar per row of a grouped source, sized
 * by a number column and named by one or more label columns, side by side. "money" formats
 * the values as amounts:
 *
 *   <pre>{$forms.rows|herald_bars:"sum":"form":"money"}</pre>
 *   <pre>{$activities.rows|herald_bars:"count":"type,status"}</pre>
 */
final class BarsModifier {

  public const NAME = 'herald_bars';

  private const MAX_BLOCKS = 12;

  /**
   * Smarty refuses to register a modifier twice.
   */
  public static function register(): void {
    $smarty = \CRM_Core_Smarty::singleton();
    if ($smarty->getRegisteredPlugin('modifier', self::NAME) === NULL) {
      $smarty->registerPlugin('modifier', self::NAME, [self::class, 'apply']);
    }
  }

  /**
   * Labels arrive HTML-escaped like every value, so they pass through as they are.
   *
   * @throws DigestProblem
   */
  public static function apply(mixed $rows, mixed $column = NULL, mixed $labels = '', mixed $format = ''): string {
    [$rows, $column] = ChartRows::of(self::NAME, $rows, $column);
    $values = ChartRows::numbers(self::NAME, $rows, $column);
    $names = array_values(array_filter(array_map(trim(...), explode(',', is_string($labels) ? $labels : ''))));
    if ($names === []) {
      throw new DigestProblem([E::ts(
        '%1 needs the columns that name each bar, e.g. {$forms.rows|%1:"sum":"form"}.',
        [1 => self::NAME],
      )]);
    }
    if (!in_array($format, ['', 'money'], TRUE)) {
      throw new DigestProblem([E::ts('%1: the format must be "money" or left out.', [1 => self::NAME])]);
    }
    foreach ($names as $name) {
      ChartRows::of(self::NAME, $rows, $name);
    }
    $max = max([0.0, ...array_filter($values, is_float(...))]);
    if ($rows === [] || $max <= 0) {
      return E::ts('(no data)');
    }
    $lines = [];
    foreach ($rows as $index => $row) {
      $value = $values[$index] ?? 0.0;
      $lines[] = [
        'labels' => array_map(static fn (string $name): string => self::text($row[$name]), $names),
        'value' => $format === 'money' ? \Civi::format()->money($value) : NiceNumber::label($value),
        'size' => $value,
      ];
    }
    return TextBars::render($lines, self::MAX_BLOCKS);
  }

  private static function text(mixed $value): string {
    return is_scalar($value) && (string) $value !== '' ? (string) $value : E::ts('(none)');
  }

}
