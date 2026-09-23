<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Splitting a body into text and tags, so line-break handling never touches
 * the inside of a tag and finds <pre> only where it is a real tag.
 */
final class HtmlTokens {

  private const BLOCK = [
    'address', 'article', 'blockquote', 'br', 'dd', 'div', 'dl', 'dt', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'header', 'hr', 'li', 'ol', 'p', 'pre', 'section', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
  ];

  /**
   * @return list<string> Even indexes are text, odd ones tags or comments.
   */
  public static function split(string $html): array {
    return preg_split('#(<!--.*?(?:-->|$)|<[a-zA-Z/!?][^>]*>)#s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
  }

  /**
   * Lower-case element name of a tag, '' for a comment.
   */
  public static function name(string $tag): string {
    return preg_match('#^</?([a-zA-Z][a-zA-Z0-9-]*)#', $tag, $m) === 1 ? strtolower($m[1]) : '';
  }

  public static function isClosing(string $tag): bool {
    return str_starts_with($tag, '</');
  }

  /**
   * Whitespace next to a block tag is source layout, not a line break.
   */
  public static function isBlock(?string $tag): bool {
    return $tag !== NULL && in_array(self::name($tag), self::BLOCK, TRUE);
  }

  /**
   * @return array{string, string, string} Leading whitespace, the text between, trailing whitespace.
   */
  public static function edges(string $text): array {
    $whitespace = " \t\n\r\f\v";
    $lead = strspn($text, $whitespace);
    $core = rtrim(substr($text, $lead), $whitespace);
    return [substr($text, 0, $lead), $core, substr($text, $lead + strlen($core))];
  }

  /**
   * Whether $tag opens or closes a <pre>; $inPre unchanged for other tags.
   */
  public static function inPreAfter(string $tag, bool $inPre): bool {
    return self::name($tag) === 'pre' ? !self::isClosing($tag) : $inPre;
  }

}
