<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * Coercions for untyped setting / persisted-state values.
 *
 * Non-scalars are config errors, not values, so they collapse to the
 * documented default instead of PHP's cast of an array.
 */
class Coerce {

  /**
   * Coerce to int.
   */
  public static function int(mixed $value, int $default = 0): int {
    return is_scalar($value) ? (int) $value : $default;
  }

  /**
   * Coerce to string.
   */
  public static function str(mixed $value, string $default = ''): string {
    return is_scalar($value) ? (string) $value : $default;
  }

}
