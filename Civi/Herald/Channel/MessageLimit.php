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
 * The cut is taken on a paragraph or line boundary, measured in the unit the
 * transport counts ({@see Utf16Text}, {@see HtmlPrefix}), and fitHtml() closes
 * any tag left open ({@see HtmlRepair}).
 */
final class MessageLimit {

  private const MODE_HTML = 'html';

  private const MODE_TEXT = 'text';

  /**
   * Length in UTF-16 code units, the unit transports actually count.
   */
  public static function length(string $text): int {
    return Utf16Text::length($text);
  }

  /**
   * Length as a transport that parses markup counts it.
   */
  public static function htmlTextLength(string $html): int {
    return HtmlPrefix::textLength($html);
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
    return ($mode === self::MODE_HTML ? HtmlRepair::closeOpenTags($head) : rtrim($head)) . "\n\n" . $marker;
  }

  private static function measure(string $text, string $mode): int {
    return $mode === self::MODE_HTML ? HtmlPrefix::textLength($text) : Utf16Text::length($text);
  }

  private static function cut(string $text, int $max, string $mode): string {
    return $mode === self::MODE_HTML ? HtmlPrefix::cut($text, $max) : Utf16Text::cut($text, $max);
  }

}
