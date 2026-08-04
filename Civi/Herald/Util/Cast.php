<?php

declare(strict_types = 1);

namespace Civi\Herald\Util;

/**
 * Coercers for untyped values that reach herald from storage.
 *
 * Action/condition parameters arrive serialised from CiviRules storage or
 * decoded from JSON, so nothing about their PHP type is guaranteed. Every read
 * of such a value goes through here rather than casting `mixed` directly.
 */
final class Cast {

  /**
   * Coerce an untyped value to string.
   *
   * Non-scalars — arrays and objects — have no meaningful string form in any
   * of these slots and collapse to $default instead of producing "Array".
   */
  public static function toString(mixed $value, string $default = ''): string {
    return is_scalar($value) ? (string) $value : $default;
  }

  /**
   * Coerce an untyped value to int. See toString() for the non-scalar rule.
   */
  public static function toInt(mixed $value, int $default = 0): int {
    return is_scalar($value) ? (int) $value : $default;
  }

  /**
   * Coerce an untyped value to float. See toString() for the non-scalar rule.
   */
  public static function toFloat(mixed $value, float $default = 0.0): float {
    return is_scalar($value) ? (float) $value : $default;
  }

  /**
   * Keep only entries that can be addressed by name.
   *
   * Both template variables and channel config are addressed by name, but the
   * values come from JSON blobs and whatever was stored comes back — a JSON
   * array decodes to integer keys. A numeric key is not a usable Smarty
   * variable name (it could never be read as `{$0}`) and matches no channel
   * config field, so dropping it loses nothing and lets the type say what the
   * receivers actually get.
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
