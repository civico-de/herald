<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Fitting a rendered body into a transport's hard size limit.
 *
 * Pure — no channel state, no settings. A channel that has a documented
 * maximum message size calls fit() right before handing the text to its API:
 * the transport rejects an oversized message outright, so shortening it is the
 * only way the alert arrives at all.
 *
 * Two things make a naive substr() wrong here, and both are what this class
 * exists for: Telegram measures its 4096 in UTF-16 code units (an emoji counts
 * two, every character outside the BMP does), and a cut landing inside markup
 * turns a size rejection into a parse rejection. So the cut is taken on a
 * paragraph or line boundary, and fitHtml() closes any tag left open.
 */
final class MessageLimit {

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

  private const MODE_HTML = 'html';

  private const MODE_TEXT = 'text';

  /**
   * Length in the unit transports actually count: UTF-16 code units, so a
   * character outside the BMP — every emoji — counts two.
   *
   * The conservative reading: measuring long shortens early, measuring short
   * gets the message rejected. Relax only after a real send proves it.
   */
  public static function length(string $text): int {
    return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
  }

  /**
   * Length as a transport that parses markup counts it: tags contribute
   * nothing, a character reference counts as the one character it stands for.
   *
   * Telegram documents its 4096 as "characters after entities parsing", so a
   * body measured raw looks longer than the message the API will actually
   * hold — an alert dressed in markup would be shortened while it still fits.
   */
  public static function htmlTextLength(string $html): int {
    $text = preg_replace('#<[^>]*>#', '', $html) ?? $html;
    return self::length(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  /**
   * Shorten an HTML body to at most $max units of PARSED text, closing any tag
   * the cut left open so the result still parses.
   */
  public static function fitHtml(string $text, int $max, string $marker): string {
    return self::shorten($text, $max, $marker, self::MODE_HTML);
  }

  /**
   * Shorten a plain-text body to at most $max units. Anything tag-shaped in it
   * is text, so it is left exactly as it is.
   */
  public static function fitText(string $text, int $max, string $marker): string {
    return self::shorten($text, $max, $marker, self::MODE_TEXT);
  }

  /**
   * Shared body of fitHtml()/fitText(): find the cut, repair the head for the
   * mode, and append $marker on its own line.
   *
   * The cut is taken at the last blank line before the budget, falling back to
   * the last line break and finally to a hard cut — a body without any break
   * at all still has to fit. Budget and cut use the same unit the mode is
   * measured in, so markup never eats into the text that fits.
   */
  private static function shorten(string $text, int $max, string $marker, string $mode): string {
    if ($max <= 0 || self::measure($text, $mode) <= $max) {
      return $text;
    }
    $budget = $max - self::measure("\n\n" . $marker, $mode);
    if ($budget <= 0) {
      return self::cut($text, $max, $mode);
    }
    $head = self::cut($text, $budget, $mode);
    foreach (["\n\n", "\n"] as $separator) {
      $at = mb_strrpos($head, $separator);
      // A boundary in the first tenth of the head means the body is one long
      // block with an early break; cutting there would throw away almost
      // everything, so the hard cut is the better answer.
      if ($at !== FALSE && $at > (int) (mb_strlen($head) / 10)) {
        $head = mb_substr($head, 0, $at);
        break;
      }
    }
    return ($mode === self::MODE_HTML ? self::closeOpenTags($head) : rtrim($head)) . "\n\n" . $marker;
  }

  private static function measure(string $text, string $mode): int {
    return $mode === self::MODE_HTML ? self::htmlTextLength($text) : self::length($text);
  }

  private static function cut(string $text, int $max, string $mode): string {
    return $mode === self::MODE_HTML ? self::cutHtml($text, $max) : self::hardCut($text, $max);
  }

  /**
   * Longest prefix whose PARSED text fits $max units: tags are carried along
   * for free, a character reference costs the one character it stands for, and
   * only plain text is measured. Cutting on the raw string instead would spend
   * the whole budget on markup and drop most of a heavily formatted alert.
   */
  private static function cutHtml(string $html, int $max): string {
    $parts = preg_split('#(<[^>]*>|&\#?[0-9a-zA-Z]+;)#u', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
    $out = '';
    $used = 0;
    foreach ($parts as $part) {
      if ($part === '') {
        continue;
      }
      if (str_starts_with($part, '<')) {
        $out .= $part;
        continue;
      }
      if (str_starts_with($part, '&')) {
        if (($used + 1) > $max) {
          break;
        }
        $used++;
        $out .= $part;
        continue;
      }
      $room = $max - $used;
      if (self::length($part) <= $room) {
        $used += self::length($part);
        $out .= $part;
        continue;
      }
      $out .= self::hardCut($part, $room);
      break;
    }
    return $out;
  }

  /**
   * Cut to $max UTF-16 units. mb_substr counts characters, so the budget is
   * walked down until the UTF-16 measurement fits — an all-emoji body needs
   * half the characters the naive count would allow.
   */
  private static function hardCut(string $text, int $max): string {
    $chars = min(mb_strlen($text), $max);
    while ($chars > 0 && self::length(mb_substr($text, 0, $chars)) > $max) {
      $chars = (int) (($chars * $max) / self::length(mb_substr($text, 0, $chars)));
    }
    return mb_substr($text, 0, $chars);
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

  /**
   * Append a closing tag for every supported tag still open, innermost first.
   * Input is herald's own rendered output, so well-formed nesting is the
   * assumption; a stray closer without an opener is simply ignored.
   */
  private static function closeOpenTags(string $html): string {
    $html = rtrim(self::dropTrailingFragment($html));
    preg_match_all('#<\s*(/?)([a-zA-Z-]+)[^>]*>#', $html, $matches, PREG_SET_ORDER);
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

}
