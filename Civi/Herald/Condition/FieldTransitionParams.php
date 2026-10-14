<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use CRM_Herald_ExtensionUtil as E;

/**
 * The stored parameters of a "Field transition" condition as trimmed text, so
 * the snapshot and the condition name a field alike.
 */
final class FieldTransitionParams {

  /**
   * @param mixed $raw The serialized condition_params, or their array
   * @return array{entity: string, field: string, from_operator: string, from_value: string, to_operator: string, to_value: string}
   */
  public static function decode(mixed $raw): array {
    $decoded = is_string($raw) && $raw !== '' ? unserialize($raw, ['allowed_classes' => FALSE]) : $raw;
    $params = is_array($decoded) ? $decoded : [];
    $text = static fn (string $key): string => is_scalar($params[$key] ?? NULL) ? trim((string) $params[$key]) : '';
    return [
      'entity' => $text('entity'),
      'field' => $text('field'),
      'from_operator' => $text('from_operator'),
      'from_value' => $text('from_value'),
      'to_operator' => $text('to_operator'),
      'to_value' => $text('to_value'),
    ];
  }

  /**
   * Sides that can never match, keyed by their value element.
   *
   * @param array{from_operator: string, from_value: string, to_operator: string, to_value: string} $params
   * @return array<string, string>
   */
  public static function sideErrors(array $params): array {
    return array_filter([
      'from_value' => self::sideError($params['from_operator'], $params['from_value']),
      'to_value' => self::sideError($params['to_operator'], $params['to_value']),
    ]);
  }

  private static function sideError(string $operator, string $value): ?string {
    if (!in_array($operator, ['', ...FieldTransition::OPERATORS], TRUE)) {
      return E::ts('Unknown operator "%1".', [1 => $operator]);
    }
    if (in_array($operator, ['>', '<', '>=', '<='], TRUE) && !is_numeric($value)) {
      return E::ts('"%1" needs a number as its value.', [1 => $operator]);
    }
    return NULL;
  }

}
