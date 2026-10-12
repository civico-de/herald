<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Did field X of entity E transition from (operator, value) to (operator, value)?
 *
 * Generalisation of the Pending → Completed pattern: both sides are optional —
 * an unconfigured side always matches, so "became anything from Pending" and
 * "reached Completed from anywhere" are both expressible. The pre-edit value
 * comes from the caller (CiviRules original data first, herald's
 * hook_civicrm_pre snapshot as fallback); an unknown pre-edit value fails a
 * configured before-side (a missed alert beats a false one).
 * @mago-expect lint:cyclomatic-complexity
 */
final class FieldTransition {

  public const OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'is_empty', 'not_empty'];

  /**
   * TRUE iff both configured sides match. A side with an empty operator is
   * unconfigured and matches anything; at least one side must be configured.
   *
   * A one-sided condition ("became X" / "was Y before") additionally requires
   * that the value actually changed — the predicate alone would re-fire on
   * every unrelated edit of a row already in the target state. That check
   * needs the pre-edit value, so an unknown before-value fails one-sided
   * conditions too (a missed alert beats a false one). Two-sided conditions
   * state their own transition explicitly and are left alone.
   *
   * @param array{operator: string, value: string} $before
   * @param array{operator: string, value: string} $after
   * @param array{known: bool, value: mixed} $beforeState Resolved pre-edit
   *   value; known=FALSE when no pre-edit value could be resolved.
   */
  public static function matches(
    array $before,
    array $after,
    array $beforeState,
    mixed $afterValue,
  ): bool {
    if ($before['operator'] === '' && $after['operator'] === '') {
      return FALSE;
    }
    $oneSided = $before['operator'] === '' || $after['operator'] === '';
    if (($before['operator'] !== '' || $oneSided) && !$beforeState['known']) {
      return FALSE;
    }
    if ($oneSided && self::sameValue($beforeState['value'], $afterValue)) {
      return FALSE;
    }
    return self::sideMatches($before, $beforeState['value']) && self::sideMatches($after, $afterValue);
  }

  /**
   * Are two field values the same, using the equality semantics of compare()?
   * Non-scalar values (arrays, objects) fall back to strict comparison.
   */
  public static function sameValue(mixed $a, mixed $b): bool {
    if (is_scalar($a) && is_scalar($b)) {
      return self::equals($a, Cast::toStoredString($b));
    }
    return $a === $b;
  }

  /**
   * @param array{operator: string, value: string} $side
   */
  public static function sideMatches(array $side, mixed $actual): bool {
    if ($side['operator'] === '') {
      return TRUE;
    }
    return self::compare($side['operator'], $actual, $side['value']);
  }

  /**
   * Operator dispatch. Ordering operators compare numerically and require
   * both sides numeric; equality compares numerically when both sides are
   * numeric, else as trimmed strings (APIv4/DAO values arrive stringly).
   */
  public static function compare(string $operator, mixed $actual, string $expected): bool {
    $isEmpty = $actual === NULL || $actual === '' || $actual === [];
    switch ($operator) {
      case 'is_empty':
        return $isEmpty;

      case 'not_empty':
        return !$isEmpty;

      case '=':
        return self::equals($actual, $expected);

      case '!=':
        return !self::equals($actual, $expected);

      case '>':
      case '<':
      case '>=':
      case '<=':
        return self::ordered($operator, $actual, $expected);

      default:
        return FALSE;
    }
  }

  private static function ordered(string $operator, mixed $actual, string $expected): bool {
    $a = is_scalar($actual) ? trim(Cast::toStoredString($actual)) : '';
    $b = trim($expected);
    if (!is_numeric($a) || !is_numeric($b)) {
      return FALSE;
    }
    $cmp = self::floatSafe($a) && self::floatSafe($b)
      ? (float) $a <=> (float) $b
      : self::compareIntStrings($a, $b);
    return match ($operator) {
      '>' => $cmp > 0,
      '<' => $cmp < 0,
      '>=' => $cmp >= 0,
      '<=' => $cmp <= 0,
      default => FALSE,
    };
  }

  private static function equals(mixed $actual, string $expected): bool {
    if (!is_scalar($actual)) {
      return FALSE;
    }
    $actualStr = trim(Cast::toStoredString($actual));
    $expectedStr = trim($expected);
    if (is_numeric($actualStr) && is_numeric($expectedStr)) {
      // Numeric compare only while the float cast is lossless for both sides;
      // beyond 2^53 (or exotic numeric spellings) fall back to string compare
      // rather than collapsing distinct values.
      if (self::floatSafe($actualStr) && self::floatSafe($expectedStr)) {
        return (float) $actualStr === (float) $expectedStr;
      }
      return self::isIntString($actualStr) && self::isIntString($expectedStr)
        ? self::compareIntStrings($actualStr, $expectedStr) === 0
        : $actualStr === $expectedStr;
    }
    return $actualStr === $expectedStr;
  }

  /**
   * Can this numeric string round-trip through float without precision loss?
   * Conservative: at most 15 significant digits keeps every value exact.
   */
  private static function floatSafe(string $value): bool {
    $digits = preg_replace('/^0+(?=\d)|[^0-9]/', '', $value) ?? '';
    return strlen(ltrim($digits, '0') ?: '0') <= 15;
  }

  private static function isIntString(string $value): bool {
    return preg_match('/^[+-]?\d+$/', $value) === 1;
  }

  /**
   * Arbitrary-precision integer-string comparison (spaceship semantics).
   * Non-integer spellings compare as plain strings — deterministic, and only
   * reachable for numeric values too large for a safe float compare anyway.
   */
  private static function compareIntStrings(string $a, string $b): int {
    if (!self::isIntString($a) || !self::isIntString($b)) {
      return strcmp($a, $b) <=> 0;
    }
    $normalize = static function (string $v): array {
      $negative = $v[0] === '-';
      $digits = ltrim(ltrim($v, '+-'), '0') ?: '0';
      return [$digits === '0' ? FALSE : $negative, $digits];
    };
    [$aNeg, $aDigits] = $normalize($a);
    [$bNeg, $bDigits] = $normalize($b);
    if ($aNeg !== $bNeg) {
      return $aNeg ? -1 : 1;
    }
    $cmp = strlen($aDigits) <=> strlen($bDigits) ?: strcmp($aDigits, $bDigits) <=> 0;
    return $aNeg ? -$cmp : $cmp;
  }

  /**
   * Normalise a raw operator/value pair from condition params into a side spec.
   *
   * @return array{operator: string, value: string}
   */
  public static function side(mixed $operator, mixed $value): array {
    $op = is_scalar($operator) ? trim((string) $operator) : '';
    return [
      'operator' => in_array($op, self::OPERATORS, TRUE) ? $op : '',
      'value' => is_scalar($value) ? (string) $value : '',
    ];
  }

}
