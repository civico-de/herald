<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

/**
 * Spaceship order of two numeric strings: exact in decimal notation of any
 * length, as floats in exponent notation.
 */
final class NumericOrder {

  /**
   * @param numeric-string $a
   * @param numeric-string $b
   */
  public static function of(string $a, string $b): int {
    $decimal = '/^[+-]?(\d+\.?\d*|\.\d+)$/';
    if (preg_match($decimal, $a) !== 1 || preg_match($decimal, $b) !== 1) {
      return (float) $a <=> (float) $b;
    }
    return bccomp($a, $b, max(strlen(strrchr($a, '.') ?: ''), strlen(strrchr($b, '.') ?: '')));
  }

}
