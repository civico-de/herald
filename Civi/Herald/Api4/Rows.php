<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4;

/**
 * Narrows an APIv4 result to its row arrays.
 */
final class Rows {

  /**
   * @param iterable<mixed> $result
   * @return list<array<array-key, mixed>>
   */
  public static function of(iterable $result): array {
    $rows = [];
    foreach ($result as $row) {
      $rows[] = is_array($row) ? $row : [];
    }
    return $rows;
  }

}
