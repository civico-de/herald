<?php

declare(strict_types = 1);

namespace Civi\Herald\Util;

/**
 * Coercers for values from CiviRules storage or JSON, whose PHP type is not guaranteed.
 */
final class Cast {

  /**
   * Non-scalars collapse to $default instead of producing "Array".
   */
  public static function toString(mixed $value, string $default = ''): string {
    return is_scalar($value) ? (string) $value : $default;
  }

  public static function toInt(mixed $value, int $default = 0): int {
    return is_scalar($value) ? (int) $value : $default;
  }

  public static function toFloat(mixed $value, float $default = 0.0): float {
    return is_scalar($value) ? (float) $value : $default;
  }

  /**
   * A JSON list decodes to integer keys, which are neither Smarty variable
   * names nor channel config fields.
   *
   * @param array<array-key, mixed> $vars
   * @return array<string, mixed>
   */
  public static function namedOnly(array $vars): array {
    $named = [];
    foreach ($vars as $key => $value) {
      if (!is_string($key)) {
        continue;
      }

      $named[$key] = $value;
    }

    return $named;
  }

}
