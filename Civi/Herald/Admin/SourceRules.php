<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Checks herald_source_normalization rules before the admin screen stores them.
 */
final class SourceRules {

  public const SETTING = 'herald_source_normalization';

  /**
   * @param array<array-key, mixed> $rules
   * @return list<array{match: string, type: string, label: string}>
   * @throws InvalidInput
   */
  public static function clean(array $rules): array {
    $clean = [];
    $errors = [];
    foreach (array_values($rules) as $i => $rule) {
      $rule = is_array($rule) ? $rule : [];
      $problems = SourceRule::problems($rule['match'] ?? NULL, $rule['type'] ?? NULL, $rule['label'] ?? NULL);
      foreach ($problems as $problem) {
        $errors[] = E::ts('Rule %1: %2', [1 => $i + 1, 2 => $problem]);
      }
      $clean[] = [
        'match' => Cast::toString($rule['match'] ?? NULL),
        'type' => Cast::toString($rule['type'] ?? NULL),
        'label' => Cast::toString($rule['label'] ?? NULL),
      ];
    }
    InvalidInput::throwIfAny($errors);
    return $clean;
  }

}
