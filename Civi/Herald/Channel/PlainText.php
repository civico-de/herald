<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Rendered bodies are HTML (msg_html); sinks that do not render HTML get the
 * text a reader would see: tags dropped, entities decoded, line breaks kept.
 *
 * Block tags and <br> become line breaks. Whitespace bordering a block tag is
 * layout and counts as at most one blank line; all other text stays exact.
 */
final class PlainText {

  public static function fromHtml(string $html): string {
    $text = new PlainTextWriter();
    $tokens = HtmlTokens::split($html);
    $inPre = FALSE;
    foreach ($tokens as $i => $token) {
      if (($i % 2) === 1) {
        $inPre = HtmlTokens::inPreAfter($token, $inPre);
        $text->tag($token);
        continue;
      }
      if ($inPre) {
        $text->append($token);
        continue;
      }
      $text->text($token, $tokens[$i - 1] ?? NULL, $tokens[$i + 1] ?? NULL);
    }
    return $text->result();
  }

  /**
   * A plain-text body as HTML that shows exactly that text.
   */
  public static function toHtml(string $text): string {
    return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
