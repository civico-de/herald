<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Herald's templates separate lines with literal newlines, which Telegram shows
 * but browsers and mail clients collapse; HTML sinks read by those get <br>.
 */
final class HtmlLineBreaks {

  public static function apply(string $html): string {
    $tokens = HtmlTokens::split($html);
    $inPre = FALSE;
    foreach ($tokens as $i => $token) {
      if (($i % 2) === 1) {
        $inPre = HtmlTokens::inPreAfter($token, $inPre);
        continue;
      }
      if (!$inPre) {
        $tokens[$i] = self::breakText($token, $tokens[$i - 1] ?? NULL, $tokens[$i + 1] ?? NULL);
      }
    }
    return implode('', $tokens);
  }

  /**
   * Newlines in text become <br>, except the whitespace run bordering a block tag.
   */
  private static function breakText(string $text, ?string $prev, ?string $next): string {
    [$lead, $core, $trail] = HtmlTokens::edges($text);
    $afterBlock = HtmlTokens::isBlock($prev);
    $beforeBlock = HtmlTokens::isBlock($next);
    if ($core === '' && ($afterBlock || $beforeBlock)) {
      return $text;
    }
    return ($afterBlock ? $lead : self::br($lead)) . self::br($core) . ($beforeBlock ? $trail : self::br($trail));
  }

  private static function br(string $text): string {
    return preg_replace('#\r?\n#', "<br>\n", $text) ?? $text;
  }

}
