<?php

declare(strict_types = 1);

namespace Civi\Herald\Cleaning;

use Civi\Herald\Admin\InvalidInput;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Checks herald_cleaned_fields entries before the admin screen stores them:
 * a text column of a database entity, named once, with valid rules.
 *
 * @phpstan-import-type CleanedField from CleanedFields
 */
final class CleanedFieldsInput {

  /**
   * @param array<array-key, mixed> $fields
   * @return list<CleanedField>
   * @throws InvalidInput
   */
  public static function clean(array $fields): array {
    $clean = [];
    $errors = [];
    $seen = [];
    foreach (array_values($fields) as $i => $entry) {
      $entry = is_array($entry) ? $entry : [];
      $rules = is_array($entry['rules'] ?? NULL) ? array_values($entry['rules']) : [];
      $cleaned = [
        'entity' => Cast::toString($entry['entity'] ?? NULL),
        'field' => Cast::toString($entry['field'] ?? NULL),
        'rules' => array_map(Rule::shape(...), $rules),
        'fallback' => trim(Cast::toString($entry['fallback'] ?? NULL)),
      ];
      $key = "{$cleaned['entity']}.{$cleaned['field']}";
      $problems = [
        ...self::fieldProblems($cleaned['entity'], $cleaned['field'], $seen[$key] ?? NULL),
        ...self::ruleProblems($rules),
      ];
      foreach ($problems as $problem) {
        $errors[] = E::ts('Field %1: %2', [1 => $i + 1, 2 => $problem]);
      }
      $seen[$key] ??= $i + 1;
      $clean[] = $cleaned;
    }
    InvalidInput::throwIfAny($errors);
    return $clean;
  }

  /**
   * @param list<mixed> $rules
   * @return list<string>
   */
  private static function ruleProblems(array $rules): array {
    $problems = [];
    foreach ($rules as $r => $rule) {
      foreach (Rule::problemsOf($rule) as $problem) {
        $problems[] = E::ts('Rule %1: %2', [1 => $r + 1, 2 => $problem]);
      }
    }
    return $problems;
  }

  /**
   * @return list<string>
   */
  private static function fieldProblems(string $entity, string $field, ?int $seenIn): array {
    $problem = TextField::problem($entity, $field);
    if ($problem !== NULL) {
      return [$problem];
    }
    return (
      $seenIn === NULL ? [] : [E::ts('%1 is already cleaned in field %2.', [1 => "{$entity}.{$field}", 2 => $seenIn])]
    );
  }

}
