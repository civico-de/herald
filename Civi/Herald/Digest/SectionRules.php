<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use CRM_Herald_ExtensionUtil as E;

/**
 * What an extension's digest section may hold: named scalars, and lists of
 * rows of named scalars, all named as template placeholders.
 */
final class SectionRules {

  /**
   * @param array<array-key, mixed> $answer
   * @return list<string>
   */
  public static function problems(array $answer): array {
    return array_values(array_filter(array_map(self::problem(...), array_keys($answer), $answer)));
  }

  private static function problem(mixed $name, mixed $value): ?string {
    if (!self::isName($name)) {
      return E::ts('the name "%1" is not lowercase letters, digits and underscores.', [1 => $name]);
    }
    if ($value === NULL || is_scalar($value)) {
      return NULL;
    }
    if (!is_array($value) || !array_is_list($value)) {
      return self::notValues($name);
    }
    foreach ($value as $row) {
      $problem = self::rowProblem($name, $row);
      if ($problem !== NULL) {
        return $problem;
      }
    }
    return NULL;
  }

  private static function rowProblem(string $name, mixed $row): ?string {
    if (
      !is_array($row)
      || array_filter(
        $row,
        static fn (mixed $cell): bool => $cell !== NULL
        && !is_scalar($cell),
      ) !== []
    ) {
      return self::notValues($name);
    }
    $unnamed = array_filter(array_keys($row), static fn (int|string $column): bool => !self::isName($column));
    return (
      $unnamed === []
        ? NULL
        : E::ts(
          'a row of "%1" names a value "%2", which is not lowercase letters, digits and underscores.',
          [1 => $name, 2 => reset($unnamed)],
        )
    );
  }

  private static function notValues(string $name): string {
    return E::ts('"%1" is neither a value nor a list of rows of values.', [1 => $name]);
  }

  /**
   * @phpstan-assert-if-true string $name
   */
  private static function isName(mixed $name): bool {
    return is_string($name) && preg_match(Placeholders::NAME, $name) === 1;
  }

}
