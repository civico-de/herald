<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Rendered bodies are HTML (msg_html); sinks that do not render HTML get the
 * text a reader would see: tags dropped, entities decoded, line breaks kept.
 */
final class PlainText {

  public static function fromHtml(string $html): string {
    $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
    return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

}
