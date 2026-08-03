<?php

namespace Civi\Herald\Privacy;

/**
 * Partial masking of personal data for chat notifications: show just enough
 * to recognise a signup (and spot spam), never the full identity.
 *
 * Rule: per word, keep the first 2 characters and replace every further
 * character with one '*'. Emails are masked on both sides of the '@' — local
 * part and every domain label — only the TLD stays readable.
 */
final class Mask {

  /**
   * "Johannes Filter" → "Jo****** Fi****"
   */
  public static function words(string $value): string {
    // @mago-expect lint:no-shorthand-ternary
    $words = preg_split('/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
    return implode('', array_map(
      static fn (string $part) => preg_match('/^\s+$/u', $part) ? $part : self::partial($part),
      $words,
    ));
  }

  /**
   * "Johannes" → "Jo******"; "Al" → "Al".
   */
  public static function partial(string $word): string {
    $len = mb_strlen($word);
    if ($len <= 2) {
      return $word;
    }
    return mb_substr($word, 0, 2) . str_repeat('*', $len - 2);
  }

  /**
   * "johannes@example.org" → "jo******@ex*****.org". Values without an '@'
   * fall back to plain word masking.
   */
  public static function email(string $email): string {
    $at = mb_strrpos($email, '@');
    if ($at === FALSE) {
      return self::words($email);
    }
    $local = mb_substr($email, 0, $at);
    $labels = explode('.', mb_substr($email, $at + 1));
    $tld = count($labels) > 1 ? array_pop($labels) : NULL;
    $domain = implode('.', array_map([self::class, 'partial'], $labels));
    return self::partial($local) . '@' . $domain . ($tld === NULL ? '' : '.' . $tld);
  }

}
