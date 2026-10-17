<?php

declare(strict_types = 1);

/**
 * Turns a PHP preg pattern into the database's REGEXP syntax.
 */
final class CRM_Herald_Upgrader_PhpPattern {

  private const CLOSING = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];

  private const INLINE = ['m', 's', 'x'];

  /**
   * "/^bank/im" becomes "(?m)^bank": the database matches case-insensitively
   * and in UTF-8 anyway, and m, s and x move into the pattern.
   */
  public static function toSql(string $php): string {
    $open = substr($php, 0, 1);
    $end = $open === '' || ctype_alnum($open) || $open === '\\' ? FALSE : strrpos($php, self::CLOSING[$open] ?? $open);
    if (!$end) {
      Civi::log('herald')->warning('herald: source rule pattern {pattern} has no delimiters, kept as is', [
        'pattern' => $php,
      ]);
      return $php;
    }
    $flags = str_split(substr($php, $end + 1));
    $inline = implode('', array_intersect($flags, self::INLINE));
    $dropped = array_diff($flags, [...self::INLINE, 'i', 'u', '']);
    if ($dropped !== []) {
      Civi::log('herald')->warning('herald: source rule pattern {pattern} loses the flags {flags}', [
        'pattern' => $php,
        'flags' => implode('', $dropped),
      ]);
    }
    return ($inline === '' ? '' : "(?{$inline})") . substr($php, 1, $end - 1);
  }

}
