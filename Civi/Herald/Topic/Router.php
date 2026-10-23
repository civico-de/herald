<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

/**
 * Maps a topic to delivery parameters via the `herald_topics` setting, whose
 * entries use the same parameter shape as the CiviRules actions.
 *
 *   cv api4 Setting.set +v 'herald_topics={
 *     "default": {"channel": "email"},
 *     "ops":     {"channel": "ops_bot", "chat_id": "-100…", "aggregate_minutes": 0}
 *   }'
 *
 * The topic's entry is merged over "default"; neither present, or no
 * channel named (a configured channel, see ChannelStore), throws. A route
 * to subscribers reaches the topic's own unless it names subscribers_of; a
 * topic contacts subscribe to ignores the subscribers_of of "default".
 */
class Router {

  public const SETTING = 'herald_topics';

  /**
   * @return array<string, mixed> Delivery params for Delivery::send().
   */
  public function resolve(string $topic): array {
    $map = \Civi::settings()->get(self::SETTING);
    return self::resolveFrom(is_array($map) ? $map : [], $topic);
  }

  /**
   * Whether publishing to the topic finds a route.
   */
  public static function exists(string $topic): bool {
    $map = \Civi::settings()->get(self::SETTING);
    return is_array($map) && self::entries($map, $topic) !== [];
  }

  /**
   * Pure resolution against a given map.
   *
   * @param array<array-key, mixed> $map
   * @return array<string, mixed>
   */
  public static function resolveFrom(array $map, string $topic): array {
    $entries = self::entries($map, $topic);
    if ($entries === []) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: there is no topic "%s" — add it (or a "default" topic) to the %s setting.',
        $topic,
        self::SETTING,
      ));
    }
    // Unknown keys are ignored by the consumer rather than rejected here.
    /** @var array<string, mixed> $params */
    $params = array_merge(...$entries);
    $mode = $params['delivery_mode'] ?? 'direct';
    // A fan-out reaches each contact on their own channel.
    if (empty($params['channel']) && !in_array($mode, ['subscribers', 'group'], TRUE)) {
      throw new \CRM_Core_Exception(sprintf('herald: the topic "%s" names no channel.', $topic));
    }
    if ($mode === 'subscribers') {
      $params['subscribers_of'] = TopicMeta::subscribersOf($topic, $map[$topic] ?? NULL, $params);
    }
    return $params;
  }

  /**
   * The "default" entry and the topic's own, in merge order, where they are arrays.
   *
   * @param array<array-key, mixed> $map
   * @return list<array<array-key, mixed>>
   */
  private static function entries(array $map, string $topic): array {
    $entries = array_filter([$map['default'] ?? NULL, $map[$topic] ?? NULL], 'is_array');
    return array_values(array_map(TopicMeta::route(...), $entries));
  }

}
