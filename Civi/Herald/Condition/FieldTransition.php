<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

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
    if ($before['operator'] !== '' && !$beforeState['known']) {
      return FALSE;
    }
    return self::sideMatches($before, $beforeState['value']) && self::sideMatches($after, $afterValue);
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
    if (!is_numeric($expected) || !is_scalar($actual) || !is_numeric((string) $actual)) {
      return FALSE;
    }
    $a = (float) $actual;
    $b = (float) $expected;
    return match ($operator) {
      '>' => $a > $b,
      '<' => $a < $b,
      '>=' => $a >= $b,
      '<=' => $a <= $b,
      default => FALSE,
    };
  }

  private static function equals(mixed $actual, string $expected): bool {
    if (!is_scalar($actual)) {
      return FALSE;
    }
    $actualStr = trim((string) $actual);
    $expectedStr = trim($expected);
    if (is_numeric($actualStr) && is_numeric($expectedStr)) {
      return (float) $actualStr === (float) $expectedStr;
    }
    return $actualStr === $expectedStr;
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
