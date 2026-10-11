<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Util\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Digest block from a SearchKit saved search: herald runs the search's query
 * as the system, like its own digest queries, and hands the rows to a template.
 */
final class SearchBlock {

  /**
   * @param string $name SavedSearch name
   * @return array{rows: list<array{cells: list<string>, values: array<string, string>}>, count: int}
   */
  public static function vars(string $name): array {
    $saved = \Civi\Api4\SavedSearch::get(checkPermissions: FALSE)
      ->addSelect('api_entity', 'api_params')
      ->addWhere('name', '=', $name)
      ->execute()
      ->first();
    if (!is_array($saved)) {
      throw new \CRM_Core_Exception("herald: saved search \"{$name}\" does not exist");
    }
    $params = is_array($saved['api_params'] ?? NULL) ? $saved['api_params'] : [];
    unset($params['version']);
    $params['checkPermissions'] = FALSE;
    $columns = self::columns(is_array($params['select'] ?? NULL) ? $params['select'] : []);
    $rows = [];
    foreach (civicrm_api4(Cast::toString($saved['api_entity']), 'get', $params) as $row) {
      $rows[] = self::row(is_array($row) ? $row : [], $columns);
    }
    return ['rows' => $rows, 'count' => count($rows)];
  }

  /**
   * Why the block cannot run, or NULL when the saved search exists.
   */
  public static function problem(string $name): ?string {
    $found = \Civi\Api4\SavedSearch::get(checkPermissions: FALSE)
      ->addWhere('name', '=', $name)
      ->selectRowCount()
      ->execute()
      ->countMatched();
    return $found > 0 ? NULL : E::ts('saved search "%1" does not exist.', [1 => $name]);
  }

  /**
   * Result keys of the search's columns, in order, as APIv4 names them
   * (an alias, else e.g. "COUNT:id"). [] for a wildcard select, which keeps every key.
   *
   * @param array<array-key, mixed> $select
   * @return list<string>
   */
  public static function columns(array $select): array {
    $columns = [];
    foreach ($select as $expression) {
      $expression = trim(Cast::toString($expression));
      if ($expression === '*' || str_ends_with($expression, '.*')) {
        return [];
      }
      $columns[] = \Civi\Api4\Query\SqlExpression::convert($expression, parseAlias: TRUE)->getAlias();
    }
    return $columns;
  }

  /**
   * Cells keep the search's column order; values are keyed by the column
   * with non-word characters as "_" (financial_type_id:label → financial_type_id_label).
   * APIv4 adds `id` to ungrouped rows; only the search's own columns count.
   *
   * @param array<array-key, mixed> $row
   * @param list<string> $columns
   * @return array{cells: list<string>, values: array<string, string>}
   */
  public static function row(array $row, array $columns): array {
    if ($columns !== []) {
      $row = array_merge(array_fill_keys($columns, NULL), array_intersect_key($row, array_flip($columns)));
    }
    $cells = [];
    $values = [];
    foreach ($row as $key => $value) {
      $text = is_array($value) ? implode(', ', array_map(Cast::toString(...), $value)) : Cast::toString($value);
      $cells[] = $text;
      $values[(string) preg_replace('/\W/', '_', (string) $key)] = $text;
    }
    return ['cells' => $cells, 'values' => $values];
  }

}
