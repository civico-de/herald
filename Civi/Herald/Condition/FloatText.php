<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

/**
 * A float as the shortest decimal text that reads back as the same float.
 * The precision is explicit, so neither precision nor serialize_precision applies.
 */
final class FloatText {

  public static function of(float $value): string {
    foreach ([15, 16] as $digits) {
      $text = sprintf("%.{$digits}H", $value);
      if ((float) $text === $value) {
        return $text;
      }
    }
    return sprintf('%.17H', $value);
  }

}
