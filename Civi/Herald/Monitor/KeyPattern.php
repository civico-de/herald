<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * A check key, or a prefix ending in `*`, as used by the threshold exceptions and the snoozes.
 */
final class KeyPattern {

  /**
   * No whitespace and no colon (a key ends before the detail); `*` only at the end.
   */
  public const REGEX = '/^[^\s:*]+\*?$/';

  public static function isValid(string $pattern): bool {
    return preg_match(self::REGEX, $pattern) === 1;
  }

  /**
   * How well a pattern matches: NULL for no match, else the matched length;
   * an exact key beats a prefix of the same length.
   */
  public static function score(string $pattern, string $key): ?int {
    if (!str_ends_with($pattern, '*')) {
      return $pattern === $key ? strlen($pattern) + 1 : NULL;
    }
    $prefix = substr($pattern, 0, -1);
    return str_starts_with($key, $prefix) ? strlen($prefix) : NULL;
  }

  /**
   * The value of the longest pattern in the map that matches the key.
   *
   * @template T
   * @param array<string, T> $map
   * @return T|null
   */
  public static function lookup(array $map, string $key): mixed {
    $best = NULL;
    $bestScore = -1;
    foreach ($map as $pattern => $value) {
      $score = self::score((string) $pattern, $key);
      if ($score === NULL || $score <= $bestScore) {
        continue;
      }
      $best = $value;
      $bestScore = $score;
    }
    return $best;
  }

}
