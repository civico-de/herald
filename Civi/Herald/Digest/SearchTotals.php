<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * The SUM and COUNT columns of a search: SQL sums no rows to NULL, a digest reports 0.
 */
final class SearchTotals {

  /**
   * Column key => 0 for each aggregate total the search selects.
   *
   * @return array<string, int>
   */
  public static function of(mixed $params): array {
    $select = is_array($params) && is_array($params['select'] ?? NULL) ? $params['select'] : [];
    $totals = [];
    foreach ($select as $expression) {
      if (!is_string($expression) || preg_match('/^(?:SUM|COUNT)\(.*\) AS (\w+)$/i', $expression, $match) !== 1) {
        continue;
      }
      $totals[$match[1]] = 0;
    }
    return $totals;
  }

}
