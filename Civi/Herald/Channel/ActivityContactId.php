<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Validates contact IDs accepted from channel configuration.
 */
final class ActivityContactId {

  public static function optional(mixed $value): ?int {
    if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
      return NULL;
    }
    $value = (int) $value;
    return $value > 0 ? $value : NULL;
  }

}
