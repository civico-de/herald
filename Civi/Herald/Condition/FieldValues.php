<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Comparison of field values as APIv4 and the DAO return them: stringly,
 * numeric where both sides are numbers, exact beyond float precision.
 */
final class FieldValues {

  /**
   * Are two field values the same, using the equality semantics of compare()?
   * Non-scalar values (arrays, objects) fall back to strict comparison.
   */
  public static function same(mixed $a, mixed $b): bool {
    if (is_scalar($a) && is_scalar($b)) {
      return self::equals($a, self::text($b));
    }
    return $a === $b;
  }

  /**
   * Operator dispatch. Ordering operators compare numerically and require
   * both sides numeric; equality compares numerically when both sides are
   * numeric, else as trimmed strings (APIv4/DAO values arrive stringly).
   */
  public static function compare(string $operator, mixed $actual, string $expected): bool {
    $isEmpty = in_array($actual, [NULL, '', []], TRUE);
    return match ($operator) {
      'is_empty' => $isEmpty,
      'not_empty' => !$isEmpty,
      '=' => self::equals($actual, $expected),
      '!=' => !self::equals($actual, $expected),
      '>' => self::ordered($actual, $expected, static fn (int $cmp): bool => $cmp > 0),
      '<' => self::ordered($actual, $expected, static fn (int $cmp): bool => $cmp < 0),
      '>=' => self::ordered($actual, $expected, static fn (int $cmp): bool => $cmp >= 0),
      '<=' => self::ordered($actual, $expected, static fn (int $cmp): bool => $cmp <= 0),
      default => throw new \CRM_Core_Exception("herald: unknown comparison operator \"{$operator}\""),
    };
  }

  /**
   * @param \Closure(int): bool $holds
   */
  private static function ordered(mixed $actual, string $expected, \Closure $holds): bool {
    $a = is_scalar($actual) ? trim(self::text($actual)) : '';
    $b = trim($expected);
    return is_numeric($a) && is_numeric($b) && $holds(self::order($a, $b));
  }

  private static function equals(mixed $actual, string $expected): bool {
    if (!is_scalar($actual)) {
      return FALSE;
    }
    $a = trim(self::text($actual));
    $b = trim($expected);
    return is_numeric($a) && is_numeric($b) ? self::order($a, $b) === 0 : $a === $b;
  }

  /**
   * APIv4 returns Money and Float as floats; a string cast keeps only 14 digits.
   */
  private static function text(bool|float|int|string $value): string {
    return is_float($value) ? FloatText::of($value) : Cast::toStoredString($value);
  }

  /**
   * Spaceship order of two numeric strings: exact in decimal notation of any
   * length, as floats in exponent notation.
   *
   * @param numeric-string $a
   * @param numeric-string $b
   */
  private static function order(string $a, string $b): int {
    $decimal = '/^[+-]?(\d+\.?\d*|\.\d+)$/';
    if (preg_match($decimal, $a) !== 1 || preg_match($decimal, $b) !== 1) {
      return (float) $a <=> (float) $b;
    }
    return bccomp($a, $b, max(strlen(strrchr($a, '.') ?: ''), strlen(strrchr($b, '.') ?: '')));
  }

}
