<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Comparison of field values as APIv4 and the DAO return them: stringly,
 * numeric where both sides are numbers, exact beyond float precision.
 */
final class FieldValues {

  private const EMPTY = [NULL, '', []];

  /**
   * Are two field values the same, using the equality semantics of compare()?
   * NULL, "" and [] are one empty value; other non-scalars compare strictly.
   */
  public static function same(mixed $a, mixed $b): bool {
    if (in_array($a, self::EMPTY, TRUE) || in_array($b, self::EMPTY, TRUE)) {
      return in_array($a, self::EMPTY, TRUE) && in_array($b, self::EMPTY, TRUE);
    }
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
    $isEmpty = in_array($actual, self::EMPTY, TRUE);
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
    return is_numeric($a) && is_numeric($b) && $holds(NumericOrder::of($a, $b));
  }

  private static function equals(mixed $actual, string $expected): bool {
    if (in_array($actual, self::EMPTY, TRUE)) {
      return trim($expected) === '';
    }
    if (!is_scalar($actual)) {
      return FALSE;
    }
    $a = trim(self::text($actual));
    $b = trim($expected);
    return is_numeric($a) && is_numeric($b) ? NumericOrder::of($a, $b) === 0 : $a === $b;
  }

  /**
   * APIv4 returns Money and Float as floats; a string cast keeps only 14 digits.
   */
  private static function text(bool|float|int|string $value): string {
    return is_float($value) ? FloatText::of($value) : Cast::toStoredString($value);
  }

}
