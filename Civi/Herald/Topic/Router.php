<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

/**
 * Maps a topic to delivery parameters via the `herald_topics` setting, whose
 * entries use the same parameter shape as the CiviRules actions.
 *
 *   cv api4 Setting.set +v 'herald_topics={
 *     "default": {"channel": "telegram"},
 *     "ops":     {"channel": "telegram", "chat_id": "-100…", "aggregate_minutes": 0}
 *   }'
 *
 * The topic's entry is merged over "default"; neither present throws.
 */
class Router {

  public const SETTING = 'herald_topics';

  /**
   * @return array<string, mixed> Delivery params for AbstractSend::deliver().
   */
  public function resolve(string $topic): array {
    $map = \Civi::settings()->get(self::SETTING);
    return self::resolveFrom(is_array($map) ? $map : [], $topic);
  }

  /**
   * Pure resolution against a given map.
   *
   * @param array<array-key, mixed> $map
   * @return array<string, mixed>
   */
  public static function resolveFrom(array $map, string $topic): array {
    $default = is_array($map['default'] ?? NULL) ? $map['default'] : NULL;
    $entry = is_array($map[$topic] ?? NULL) ? $map[$topic] : NULL;
    if ($entry === NULL && $default === NULL) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: no route for topic "%s" — add it (or a "default" entry) to the %s setting.',
        $topic,
        self::SETTING,
      ));
    }
    // Unknown keys are ignored by the consumer rather than rejected here.
    /** @var array<string, mixed> $params */
    $params = array_merge($default ?? [], $entry ?? []);
    $params += ['channel' => 'telegram'];
    return $params;
  }

}
