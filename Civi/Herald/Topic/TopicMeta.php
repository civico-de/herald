<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The keys of a herald_topics entry that describe the topic rather than its
 * route: they never reach a delivery, and "default" does not pass them on.
 */
final class TopicMeta {

  public const KEYS = ['label', 'subscribable'];

  /**
   * @param array<array-key, mixed> $entry
   * @return array<array-key, mixed>
   */
  public static function route(array $entry): array {
    return array_diff_key($entry, array_flip(self::KEYS));
  }

  /**
   * @param array<array-key, mixed> $entry
   */
  public static function label(string $topic, array $entry): string {
    $label = trim(Cast::toString($entry['label'] ?? ''));
    return $label === '' ? $topic : $label;
  }

  /**
   * Name => title of the topics contacts may subscribe to.
   *
   * @param array<array-key, mixed> $map A herald_topics value
   * @return array<string, string>
   */
  public static function subscribable(array $map): array {
    $out = [];
    foreach ($map as $topic => $entry) {
      if (!is_array($entry) || ($entry['subscribable'] ?? FALSE) !== TRUE) {
        continue;
      }
      $out[(string) $topic] = self::label((string) $topic, $entry);
    }
    return $out;
  }

  /**
   * The topic whose subscribers a route in subscriber mode reaches.
   *
   * @param array<array-key, mixed> $merged The route merged over "default"
   */
  public static function subscribersOf(string $topic, mixed $entry, array $merged): string {
    $own = is_array($entry) ? $entry : [];
    $of = ($own['subscribable'] ?? FALSE) === TRUE ? $own['subscribers_of'] ?? '' : $merged['subscribers_of'] ?? '';
    return empty($of) ? $topic : Cast::toString($of);
  }

}
