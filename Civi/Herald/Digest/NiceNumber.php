<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * Numbers of the 1-2-5 series, and short labels for histogram edges.
 */
final class NiceNumber {

  private const MANTISSAS = [1, 2, 5];

  /**
   * Round x up to the next 1-2-5 series number.
   */
  public static function ceil(float $x): float {
    if ($x <= 0) {
      return 0.0;
    }
    $exp = (int) floor(log10($x));
    $mantissa = $x / (10 ** $exp);
    foreach (self::MANTISSAS as $m) {
      if ($mantissa <= ($m + 1e-9)) {
        return $m * (10 ** $exp);
      }
    }
    return 10 * (10 ** $exp);
  }

  /**
   * Round x down to the previous 1-2-5 series number.
   */
  public static function floor(float $x): float {
    if ($x <= 0) {
      return 0.0;
    }
    $exp = (int) floor(log10($x));
    $mantissa = $x / (10 ** $exp);
    foreach (array_reverse(self::MANTISSAS) as $m) {
      if ($mantissa >= ($m - 1e-9)) {
        return $m * (10 ** $exp);
      }
    }
    return 10 ** $exp;
  }

  /**
   * "2k", "1.5k", "0.5" or "20".
   */
  public static function label(float $n): string {
    if ($n >= 1000) {
      return self::trimmed($n / 1000, 1) . 'k';
    }
    return self::trimmed($n, 2);
  }

  private static function trimmed(float $n, int $decimals): string {
    return rtrim(rtrim(number_format($n, $decimals, '.', ''), '0'), '.');
  }

}
