<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use CRM_Herald_ExtensionUtil as E;

/**
 * The rows a chart modifier gets: a source's `rows`, read by column name.
 */
final class ChartRows {

  /**
   * The rows and the column they all have.
   *
   * @return array{list<array<array-key, mixed>>, string}
   * @throws DigestProblem
   */
  public static function of(string $modifier, mixed $rows, mixed $column): array {
    if (!is_array($rows) || !array_is_list($rows) || !is_string($column) || $column === '') {
      throw new DigestProblem([E::ts(
        '%1 needs the rows of a source and a column, e.g. {$amounts.rows|%1:"amount"}.',
        [1 => $modifier],
      )]);
    }
    foreach ($rows as $row) {
      if (!is_array($row) || !array_key_exists($column, $row)) {
        throw new DigestProblem([E::ts('%1: the rows have no column "%2".', [1 => $modifier, 2 => $column])]);
      }
    }
    /** @var list<array<array-key, mixed>> $rows */
    return [$rows, $column];
  }

  /**
   * The column as numbers, NULL where a row has no value.
   *
   * @param list<array<array-key, mixed>> $rows
   * @return list<float|null>
   * @throws DigestProblem
   */
  public static function numbers(string $modifier, array $rows, string $column): array {
    return array_map(static function (array $row) use ($modifier, $column): ?float {
      $value = $row[$column];
      if ($value !== NULL && !is_numeric($value)) {
        throw new DigestProblem([E::ts('%1: the column "%2" holds a value that is not a number.', [
          1 => $modifier,
          2 => $column,
        ])]);
      }
      return $value === NULL ? NULL : (float) $value;
    }, $rows);
  }

}
