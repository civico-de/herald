<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

/**
 * The string entries of a setting map; other entries and non-arrays yield nothing.
 */
final class StringMap {

  /**
   * @return array<string, string>
   */
  public static function of(mixed $value): array {
    $map = [];
    foreach (is_array($value) ? $value : [] as $key => $item) {
      if (!is_string($item)) {
        continue;
      }
      $map[(string) $key] = $item;
    }
    return $map;
  }

}
