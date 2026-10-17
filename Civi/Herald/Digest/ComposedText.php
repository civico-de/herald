<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The subjects and texts the digest form composed with its submitted sources
 * in this request; Afform saves those sources only after the digest.
 */
final class ComposedText {

  /**
   * @param array<string, mixed> $values
   */
  public static function remember(array $values): void {
    \Civi::$statics[self::class] = [...self::all(), self::key($values)];
  }

  /**
   * Whether a save writes a subject or text the form has not composed.
   *
   * @param array<string, mixed> $values
   */
  public static function isNew(array $values): bool {
    return (
      array_intersect_key($values, array_flip(['msg_subject', 'msg_html'])) !== []
      && !in_array(self::key($values), self::all(), TRUE)
    );
  }

  /**
   * @return list<string>
   */
  private static function all(): array {
    $all = \Civi::$statics[self::class] ?? [];
    return is_array($all) ? array_values(array_map(Cast::toString(...), $all)) : [];
  }

  /**
   * @param array<string, mixed> $values
   */
  private static function key(array $values): string {
    return Cast::toString($values['msg_subject'] ?? '') . "\0" . Cast::toString($values['msg_html'] ?? '');
  }

}
