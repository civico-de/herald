<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Measuring and cutting HTML by its PARSED text, the way a transport that
 * parses markup counts it: tags contribute nothing, a character reference
 * counts as the one character it stands for.
 *
 * Telegram documents its 4096 as "characters after entities parsing", so a
 * body measured raw looks longer than the message the API will actually
 * hold — an alert dressed in markup would be shortened while it still fits.
 */
final class HtmlPrefix {

  public static function textLength(string $html): int {
    $text = preg_replace('#<[^>]*>#', '', $html) ?? $html;
    return Utf16Text::length(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  /**
   * Longest prefix whose parsed text fits $max units: tags are carried along
   * for free, a character reference costs one, and only plain text is
   * measured. Cutting on the raw string instead would spend the whole budget
   * on markup and drop most of a heavily formatted alert.
   */
  public static function cut(string $html, int $max): string {
    $parts = preg_split('#(<[^>]*>|&\#?[0-9a-zA-Z]+;)#u', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
    $out = '';
    $used = 0;
    foreach ($parts as $part) {
      if ($part === '' || str_starts_with($part, '<')) {
        $out .= $part;
        continue;
      }
      $cost = str_starts_with($part, '&') ? 1 : Utf16Text::length($part);
      if (($used + $cost) <= $max) {
        $used += $cost;
        $out .= $part;
        continue;
      }
      if (!str_starts_with($part, '&')) {
        $out .= Utf16Text::cut($part, $max - $used);
      }
      break;
    }
    return $out;
  }

}
