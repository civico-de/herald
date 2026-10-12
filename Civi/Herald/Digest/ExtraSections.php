<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Core\Event\GenericHookEvent;

/**
 * Digest sections contributed by other extensions: numbers no query of
 * herald's can answer belong to the extension that owns them. A digest block
 * {"type": "section", "key": "myext.seats"} asks for one; the owning
 * extension answers for its own key:
 *
 *   function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
 *     if (in_array('myext.seats', $keys, TRUE)) {
 *       $sections['myext.seats'] = "🪑 <b>Seats</b>\n138 booked";
 *     }
 *   }
 *
 * $context carries the digest params under 'params' and the block's 'period'.
 */
class ExtraSections {

  public const HOOK = 'hook_civicrm_heraldDigestSections';

  /**
   * Ask the extensions for one section. An empty answer is a section with
   * nothing to report; no answer at all is a configuration error.
   *
   * @param array<string, mixed> $context
   */
  public static function answer(string $key, array $context): string {
    /** @var array<array-key, mixed> $sections */
    $sections = [];
    \Civi::dispatcher()->dispatch(self::HOOK, GenericHookEvent::create([
      'keys' => [$key],
      'context' => $context,
      'sections' => &$sections,
    ]));
    if (!is_array($sections) || !array_key_exists($key, $sections) || !is_scalar($sections[$key])) {
      throw new \CRM_Core_Exception("herald: no extension answers the digest section \"{$key}\"");
    }
    return trim((string) $sections[$key]);
  }

  /**
   * Parse a list of section keys: "a.b, c.d" or a JSON/PHP array.
   *
   * @param mixed $raw
   * @return list<string>
   */
  public static function parseKeys($raw): array {
    if (is_string($raw)) {
      $trimmed = trim($raw);
      if ($trimmed === '') {
        return [];
      }
      $decoded = $trimmed[0] === '[' ? json_decode($trimmed, TRUE) : NULL;
      $raw = is_array($decoded) ? $decoded : explode(',', $trimmed);
    }
    if (!is_array($raw)) {
      return [];
    }
    $keys = [];
    foreach ($raw as $rawKey) {
      if (!is_scalar($rawKey)) {
        continue;
      }
      $key = trim((string) $rawKey);
      if ($key !== '' && !in_array($key, $keys, TRUE)) {
        $keys[] = $key;
      }
    }
    return $keys;
  }

}
