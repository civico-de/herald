<?php

declare(strict_types = 1);

namespace Civi\Herald\Cleaning;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * One cleaning rule: text to match, how, and the group name.
 *
 * @phpstan-type RuleShape array{match: string, type: string, label: string}
 */
final class Rule {

  public const TYPES = ['contains', 'prefix', 'regex'];

  private const UNSET = ['match' => NULL, 'type' => NULL, 'label' => NULL];

  /**
   * What is wrong with a rule as entered; [] when it can be stored.
   *
   * @return list<string>
   */
  public static function problemsOf(mixed $rule): array {
    ['match' => $match, 'type' => $type, 'label' => $label] = (is_array($rule) ? $rule : []) + self::UNSET;
    return self::problems($match, $type, $label);
  }

  /**
   * @return RuleShape
   */
  public static function shape(mixed $rule): array {
    ['match' => $match, 'type' => $type, 'label' => $label] = (is_array($rule) ? $rule : []) + self::UNSET;
    return ['match' => Cast::toString($match), 'type' => Cast::toString($type), 'label' => Cast::toString($label)];
  }

  /**
   * @return list<string>
   */
  private static function problems(mixed $match, mixed $type, mixed $label): array {
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
    $regexError = $type === 'regex' && is_string($match) && $match !== '' ? DatabaseRegex::error($match) : NULL;
    if ($regexError !== NULL) {
      $problems[] = E::ts('the regular expression is invalid: %1', [1 => $regexError]);
    }
    return $problems;
  }

}
