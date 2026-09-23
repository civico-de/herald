<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Rendered bodies are HTML (msg_html); sinks that do not render HTML get the
 * text a reader would see: tags dropped, entities decoded, line breaks kept.
 */
final class PlainText {

  public static function fromHtml(string $html): string {
    // Odd parts are <pre> blocks, whose line breaks stay as they are.
    $parts = preg_split('#(<pre\b.*?</pre>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
    foreach ($parts as $i => $part) {
      if ($i % 2 === 0) {
        $part = preg_replace('#<li\b[^>]*>#i', '- ', $part) ?? $part;
        $part = preg_replace('#<(?:br\s*/?|/(?:p|div|li|tr|h[1-6]))\s*>[ \t]*\r?\n?#i', "\n", $part) ?? $part;
        $part = preg_replace('#\n(?:[ \t]*\n){2,}#', "\n\n", strip_tags($part)) ?? $part;
      }
      $parts[$i] = strip_tags($part);
    }
    return trim(html_entity_decode(implode('', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  /**
   * A plain-text body as HTML that shows exactly that text.
   */
  public static function toHtml(string $text): string {
    return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
