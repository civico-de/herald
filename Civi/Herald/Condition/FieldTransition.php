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
    if ($oneSided && FieldValues::same($beforeState['value'], $afterValue)) {
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
    return FieldValues::compare($side['operator'], $actual, $side['value']);
  }

  /**
   * Normalise a raw operator/value pair from condition params into a side
   * spec; an empty operator leaves the side unconfigured.
   *
   * @return array{operator: string, value: string}
   */
  public static function side(mixed $operator, mixed $value): array {
    $op = is_scalar($operator) ? trim((string) $operator) : '';
    if ($op !== '' && !in_array($op, self::OPERATORS, TRUE)) {
      throw new \CRM_Core_Exception("herald: unknown field transition operator \"{$op}\"");
    }
    return ['operator' => $op, 'value' => is_scalar($value) ? (string) $value : ''];
  }

}
