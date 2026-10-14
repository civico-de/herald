<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Digest block from a SearchKit display: herald runs it as the system, like
 * its own digest queries, and hands the formatted rows to a template.
 */
final class SearchBlock {

  /**
   * @return array{labels: list<string>, rows: list<array{cells: list<string>, values: array<string, string>}>, count: int}
   */
  public static function vars(int $displayId): array {
    $display = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addSelect('*', 'type:name', 'saved_search_id.name')
      ->addWhere('id', '=', $displayId)
      ->execute()
      ->first();
    if (!is_array($display)) {
      throw new \CRM_Core_Exception("herald: search display {$displayId} does not exist");
    }
    // A display applies the current user's ACLs unless it bypasses them.
    $display['acl_bypass'] = TRUE;
    /** @var list<array{columns: list<mixed>}> $result */
    $result = \Civi\Api4\SearchDisplay::run(checkPermissions: FALSE)
      ->setSavedSearch(Cast::toString($display['saved_search_id.name']))
      ->setDisplay($display)
      ->execute()
      ->getArrayCopy();
    $columns = self::columns($display);
    return [
      'labels' => array_values(array_map(static fn (array $column): string => Cast::toString(
        $column['label'] ?? '',
      ), $columns)),
      'rows' => array_map(static fn (array $row): array => self::row($row['columns'], $columns), $result),
      'count' => count($result),
    ];
  }

  /**
   * The display's value columns by position; links, buttons and menus carry no value.
   *
   * @param array<array-key, mixed> $display
   * @return array<array-key, array<array-key, mixed>>
   */
  private static function columns(array $display): array {
    $settings = is_array($display['settings'] ?? NULL) ? $display['settings'] : [];
    $columns = is_array($settings['columns'] ?? NULL) ? $settings['columns'] : [];
    return array_filter(
      $columns,
      static fn (mixed $column): bool => (
        is_array($column) && in_array($column['type'] ?? NULL, ['field', 'html'], TRUE)
      ),
    );
  }

  /**
   * Cells keep the display's column order; values are keyed by the column
   * with non-word characters as "_" (financial_type_id:label → financial_type_id_label).
   *
   * @param list<mixed> $formatted
   * @param array<array-key, array<array-key, mixed>> $columns
   * @return array{cells: list<string>, values: array<string, string>}
   */
  private static function row(array $formatted, array $columns): array {
    $cells = [];
    $values = [];
    foreach ($columns as $index => $column) {
      $value = is_array($formatted[$index] ?? NULL) ? $formatted[$index]['val'] ?? NULL : NULL;
      $text = is_array($value) ? implode(', ', array_map(Cast::toString(...), $value)) : Cast::toString($value);
      $cells[] = $text;
      $values[(string) preg_replace('/\W/', '_', Cast::toString($column['key'] ?? ''))] = $text;
    }
    return ['cells' => $cells, 'values' => $values];
  }

}
