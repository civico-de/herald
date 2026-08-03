<?php

namespace Civi\Herald\Topic;

/**
 * Maps a logical topic ("ops", "revenue", "mail", …) to delivery parameters.
 *
 * Publishers name a topic; ADMINS decide where topics land — via the
 * `herald_topics` setting, a map of topic name to the same parameter shape
 * the CiviRules actions use (channel, chat_id, aggregate_minutes, parse_mode,
 * webhook_url, …). No integration ever hardcodes a recipient.
 *
 *   cv api4 Setting.set +v 'herald_topics={
 *     "default": {"channel": "telegram"},
 *     "ops":     {"channel": "telegram", "chat_id": "-100…", "aggregate_minutes": 0}
 *   }'
 *
 * Resolution: the topic's own entry, merged over the "default" entry.
 * A topic without any matching entry falls back to "default" alone; if
 * neither exists the router throws — an unrouted publish is a config error,
 * not something to swallow.
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
   * Pure resolution against a given map. Test-seam.
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
    // Both halves come out of the herald_topics JSON object, so their keys are
    // the parameter names AbstractSend::deliver() reads; unknown keys are
    // ignored there rather than being an error here.
    /** @var array<string, mixed> $params */
    $params = array_merge($default ?? [], $entry ?? []);
    $params += ['channel' => 'telegram'];
    return $params;
  }

}
