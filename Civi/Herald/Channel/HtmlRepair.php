<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Making a cut HTML body parse again: drop the fragment the cut left
 * half-written and close every tag still open. A cut landing inside markup
 * turns a size rejection into a parse rejection, which is what this avoids.
 */
final class HtmlRepair {

  /**
   * Every non-void tag Telegram's HTML parse mode understands
   * (https://core.telegram.org/bots/api#html-style). One missing from this
   * list is one closeOpenTags() would leave open, which is the same rejection
   * the shortening exists to avoid.
   */
  private const HTML_TAGS = [
    'b',
    'strong',
    'i',
    'em',
    'u',
    'ins',
    's',
    'strike',
    'del',
    'code',
    'pre',
    'a',
    'blockquote',
    // Spoilers come in both spellings, and a custom emoji wraps its fallback.
    'tg-spoiler',
    'span',
    'tg-emoji',
  ];

  /**
   * Append a closing tag for every supported tag still open, innermost first.
   * Input is herald's own rendered output, so well-formed nesting is the
   * assumption; a stray closer without an opener is simply ignored.
   */
  public static function closeOpenTags(string $html): string {
    $html = rtrim(self::dropTrailingFragment($html));
    $matches = [];
    preg_match_all('#<\s*(/?)([a-zA-Z-]+)[^>]*>#', $html, $matches, PREG_SET_ORDER);
    /** @var list<string> $open */
    $open = [];
    foreach ($matches as $match) {
      $tag = strtolower($match[2]);
      if (!in_array($tag, self::HTML_TAGS, TRUE)) {
        continue;
      }
      if ($match[1] === '') {
        $open[] = $tag;
        continue;
      }
      // Keys preserved so the match is the INNERMOST still-open tag.
      $at = array_search($tag, array_reverse($open, preserve_keys: TRUE), strict: TRUE);
      if ($at !== FALSE) {
        unset($open[$at]);
        $open = array_values($open);
      }
    }
    foreach (array_reverse($open) as $tag) {
      $html .= '</' . $tag . '>';
    }
    return $html;
  }

  /**
   * Drop whatever the cut left half-written at the end: a tag ("<b",
   * "<a href=\"htt") or a character reference ("&am"). Neither is markup or
   * text, and a half entity is nothing closeOpenTags() could repair later.
   */
  private static function dropTrailingFragment(string $html): string {
    $lastOpen = mb_strrpos($html, '<');
    $lastClose = mb_strrpos($html, '>');
    if ($lastOpen !== FALSE && ($lastClose === FALSE || $lastClose < $lastOpen)) {
      return mb_substr($html, 0, $lastOpen);
    }
    // Only an ampersand in TEXT can be a half entity. One inside a completed
    // tag belongs to an attribute — a query string like "?a=1&b=2" carries no
    // semicolon and must not be mistaken for a truncated reference.
    $textFrom = $lastClose === FALSE ? 0 : $lastClose + 1;
    $amp = mb_strrpos($html, '&');
    if ($amp === FALSE || $amp < $textFrom) {
      return $html;
    }
    $semicolon = mb_strrpos($html, ';');
    return $semicolon === FALSE || $semicolon < $amp ? mb_substr($html, 0, $amp) : $html;
  }

}
