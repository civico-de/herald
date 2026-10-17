<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * The chart a snippet offers for a search with several rows, sized by its last
 * money column, else its last number; NULL without a number to chart. Bars
 * name each group, so they stand in for the list of a grouped search.
 *
 * @phpstan-import-type Field from Snippet
 */
final class ChartSnippet {

  private const NUMBERS = ['Money', 'Integer', 'Float'];

  /**
   * One bar per group, named by the columns that are not numbers.
   *
   * @param list<Field> $fields
   */
  public static function bars(string $name, array $fields): ?string {
    $value = self::value($fields);
    $labels = array_column(array_filter($fields, static fn (array $f): bool => !self::isNumber($f)), 'name');
    if ($value === NULL || $labels === []) {
      return NULL;
    }
    $args = [$value['name'], implode(',', $labels)];
    return self::pre($name, BarsModifier::NAME, $value['type'] === 'Money' ? [...$args, 'money'] : $args);
  }

  /**
   * How the single values of an ungrouped search spread.
   *
   * @param list<Field> $fields
   */
  public static function histogram(string $name, array $fields): ?string {
    $value = self::value($fields);
    return $value === NULL ? NULL : self::pre($name, HistogramModifier::NAME, [$value['name']]);
  }

  /**
   * @param list<string> $args
   */
  private static function pre(string $name, string $modifier, array $args): string {
    $quoted = implode('', array_map(static fn (string $arg): string => ':"' . $arg . '"', $args));
    return '<pre>{$' . $name . '.' . Placeholders::ROWS . '|' . $modifier . $quoted . '}</pre>';
  }

  /**
   * @param list<Field> $fields
   * @return Field|null
   */
  private static function value(array $fields): ?array {
    $numbers = array_filter($fields, self::isNumber(...));
    $money = array_filter($numbers, static fn (array $f): bool => $f['type'] === 'Money');
    return end($money) ?: end($numbers) ?: NULL;
  }

  /**
   * @param Field $field
   */
  private static function isNumber(array $field): bool {
    return in_array($field['type'], self::NUMBERS, TRUE);
  }

}
