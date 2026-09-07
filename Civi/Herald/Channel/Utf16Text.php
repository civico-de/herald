<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Text measured the way transports count it: in UTF-16 code units, so a
 * character outside the BMP — every emoji — counts two.
 *
 * The conservative reading: measuring long shortens early, measuring short
 * gets the message rejected. Relax only after a real send proves it.
 */
final class Utf16Text {

  public static function length(string $text): int {
    return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
  }

  /**
   * Cut to $max UTF-16 units. mb_substr counts characters, so the budget is
   * walked down until the UTF-16 measurement fits — an all-emoji body needs
   * half the characters the naive count would allow.
   */
  public static function cut(string $text, int $max): string {
    $chars = min(mb_strlen($text), $max);
    while ($chars > 0) {
      $measured = self::length(mb_substr($text, 0, $chars));
      if ($measured <= $max) {
        break;
      }
      $chars = intdiv($chars * $max, $measured);
    }
    return mb_substr($text, 0, $chars);
  }

}
