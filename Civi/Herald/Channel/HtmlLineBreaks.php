<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Herald's templates separate lines with literal newlines, which Telegram shows
 * but browsers and mail clients collapse; HTML sinks read by those get <br>.
 */
final class HtmlLineBreaks {

  public static function apply(string $html): string {
    // Odd parts are <pre> blocks with the newline closing them; a newline right
    // after a block tag is not a line break either.
    $parts = preg_split('#(<pre\b.*?</pre>[ \t]*\r?\n?)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
    foreach ($parts as $i => $part) {
      if ($i % 2 === 1) {
        continue;
      }
      $parts[$i] = preg_replace_callback(
        '#(<(?:br|/?(?:p|div|ul|ol|li|table|thead|tbody|tr|td|th|h[1-6]|blockquote))\b[^>]*>)?([ \t]*)\r?\n#i',
        static fn (array $m): string => $m[1] !== '' ? $m[0] : $m[2] . "<br>\n",
        $part,
      ) ?? $part;
    }
    return implode('', $parts);
  }

}
