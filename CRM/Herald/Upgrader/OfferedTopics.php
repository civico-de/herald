<?php

declare(strict_types = 1);

use Civi\Herald\Topic\Router;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The subscription types as topics of every domain.
 */
final class CRM_Herald_Upgrader_OfferedTopics {

  /**
   * Every subscription type becomes a topic contacts subscribe to.
   *
   * @param array<string, string> $labels
   * @return int The topics created
   */
  public static function offer(CRM_Herald_Upgrader_Domain $domain, array $labels): int {
    $map = $domain->settings()->get(Router::SETTING);
    $map = is_array($map) ? $map : [];
    $default = is_array($map['default'] ?? NULL) ? $map['default'] : [];
    $created = 0;
    foreach ($labels as $name => $label) {
      $created += isset($map[$name]) ? 0 : 1;
      $entry = self::keepsReaching(is_array($map[$name] ?? NULL) ? $map[$name] : [], $default, $name);
      $entry['subscribable'] = TRUE;
      if (trim(Cast::toString($entry['label'] ?? '')) === '' && $label !== $name) {
        $entry['label'] = $label;
      }
      $map[$name] = $entry;
    }
    $domain->settings()->set(Router::SETTING, $map);
    return $created;
  }

  /**
   * A topic contacts subscribe to reaches its own subscribers, so one that
   * reached those of "default" names them.
   *
   * @param array<array-key, mixed> $entry
   * @param array<array-key, mixed> $default
   * @return array<array-key, mixed>
   */
  private static function keepsReaching(array $entry, array $default, string $name): array {
    $of = $default['subscribers_of'] ?? $name;
    $mode = $entry['delivery_mode'] ?? $default['delivery_mode'] ?? NULL;
    return $mode === 'subscribers' && $of !== $name
      ? $entry + ['subscribers_of' => $of]
      : $entry;
  }

}
