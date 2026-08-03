<?php

namespace Civi\Herald\Digest;

use Civi\Core\Event\GenericHookEvent;

/**
 * Opt-in digest sections contributed by other extensions.
 *
 * The composed digest (Job.herald_contribution_digest with `blocks`) can only
 * report what a contribution query can answer. Numbers from a different entity
 * — booked event seats, membership counts, queue depth — belong to whoever owns
 * that domain, not to herald. This is the seam: the job's `sections` parameter
 * names the keys it wants, herald asks around, and the owning extension answers
 * for its own key.
 *
 * Contributing a section (from any extension):
 *
 *   function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
 *     if (in_array('myext.seats', $keys, TRUE)) {
 *       $sections['myext.seats'] = "🪑 <b>Seats</b>\n138 booked";
 *     }
 *   }
 *
 * $context carries the job params under 'params' and the resolved 'period', so
 * a listener can match the digest's timeframe.
 *
 * Only requested keys survive, in exactly the order the job listed them —
 * config decides what appears and where, never the order extensions happen to
 * load in.
 */
class ExtraSections {

  public const HOOK = 'hook_civicrm_heraldDigestSections';

  /**
   * Ask all extensions for the named sections.
   *
   * @param list<string> $keys
   * @param array<string, mixed> $context
   * @return list<string> Rendered section bodies, in $keys order.
   */
  public static function collect(array $keys, array $context): array {
    if (!$keys) {
      return [];
    }
    $sections = [];
    \Civi::dispatcher()->dispatch(self::HOOK, GenericHookEvent::create([
      'keys' => $keys,
      'context' => $context,
      'sections' => &$sections,
    ]));
    return self::order($sections, $keys);
  }

  /**
   * Parse the `sections` job parameter: "a.b, c.d" or a JSON/PHP array.
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
      $decoded = ($trimmed[0] === '[') ? json_decode($trimmed, TRUE) : NULL;
      $raw = is_array($decoded) ? $decoded : explode(',', $trimmed);
    }
    if (!is_array($raw)) {
      return [];
    }
    $keys = [];
    foreach ($raw as $key) {
      $key = trim((string) $key);
      if ($key !== '' && !in_array($key, $keys, TRUE)) {
        $keys[] = $key;
      }
    }
    return $keys;
  }

  /**
   * Keep only requested, non-empty sections — in the requested order.
   *
   * @param array<string, mixed> $sections
   * @param list<string> $keys
   * @return list<string>
   */
  public static function order(array $sections, array $keys): array {
    $ordered = [];
    foreach ($keys as $key) {
      $body = trim((string) ($sections[$key] ?? ''));
      if ($body !== '') {
        $ordered[] = $body;
      }
    }
    return $ordered;
  }

}
