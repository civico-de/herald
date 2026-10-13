<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use CRM_Herald_ExtensionUtil as E;

/**
 * What is wrong with one herald_source_normalization rule.
 */
final class SourceRule {

  public const TYPES = ['contains', 'prefix', 'regex'];

  /**
   * @return list<string>
   */
  public static function problems(mixed $match, mixed $type, mixed $label): array {
    $problems = [];
    if (!is_string($match) || $match === '') {
      $problems[] = E::ts('enter the text to match.');
    }
    if (!in_array($type, self::TYPES, TRUE)) {
      $problems[] = E::ts('choose how to match: contains, starts with or regular expression.');
    }
    if (!is_string($label) || trim($label) === '') {
      $problems[] = E::ts('enter the name to show.');
    }
    $regexError = $type === 'regex' && is_string($match) && $match !== '' ? RegexCheck::error($match) : NULL;
    if ($regexError !== NULL) {
      $problems[] = E::ts('the regular expression is invalid: %1', [1 => $regexError]);
    }
    return $problems;
  }

}
