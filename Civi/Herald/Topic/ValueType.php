<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use CRM_Herald_ExtensionUtil as E;

/**
 * Type check of one route value against its RouteFields entry.
 */
final class ValueType {

  /**
   * NULL means unset and always passes.
   *
   * @param array{label: string, type: string, options: list<string>} $field
   */
  public static function error(array $field, mixed $value): ?string {
    return $value === NULL || self::fits($field, $value) ? NULL : self::message($field);
  }

  /**
   * Types as the consumers read them: Cast::toInt accepts digit strings, and
   * bools arrive as 0/1 from older action forms.
   *
   * @param array{label: string, type: string, options: list<string>} $field
   */
  private static function fits(array $field, mixed $value): bool {
    return match ($field['type']) {
      'int' => is_int($value) || is_string($value) && preg_match('/^-?\d+$/', $value) === 1,
      'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1'], TRUE),
      'enum' => is_scalar($value) && in_array((string) $value, $field['options'], TRUE),
      'object' => is_array($value) && ($value === [] || !array_is_list($value)),
      default => is_string($value),
    };
  }

  /**
   * @param array{label: string, type: string, options: list<string>} $field
   */
  private static function message(array $field): string {
    $label = [1 => $field['label']];
    $options = implode(', ', array_map(static fn (string $o) => $o === '' ? '""' : $o, $field['options']));
    $messages = [
      'int' => E::ts('"%1" must be a whole number.', $label),
      'bool' => E::ts('"%1" must be yes or no.', $label),
      'enum' => E::ts('"%1" must be one of: %2.', $label + [2 => $options]),
      'object' => E::ts('"%1" must be a JSON object.', $label),
    ];
    return $messages[$field['type']] ?? E::ts('"%1" must be text.', $label);
  }

}
