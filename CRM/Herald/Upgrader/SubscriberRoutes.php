<?php

declare(strict_types = 1);

use Civi\Herald\Topic\Router;

/**
 * A route reaches its own topic's subscribers unless "default" names other ones.
 */
final class CRM_Herald_Upgrader_SubscriberRoutes {

  public function __construct(
    private readonly CRM_Herald_Upgrader_SubscriberKey $key,
  ) {}

  /**
   * @return int The routes changed
   */
  public function __invoke(CRM_Herald_Upgrader_Domain $domain): int {
    $map = $domain->settings()->get(Router::SETTING);
    if (!is_array($map)) {
      return 0;
    }
    $default = is_array($map['default'] ?? NULL) ? $map['default'] : [];
    $inherited = CRM_Herald_Upgrader_SubscriberKey::typeOf($default) !== '';
    // "default" passes its subscription type on to the routes in subscriber mode.
    $passedOn = in_array('subscribers', array_column(array_filter($map, 'is_array'), 'delivery_mode'), TRUE);
    $out = $map;
    foreach ($map as $topic => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $mode = $entry['delivery_mode'] ?? $default['delivery_mode'] ?? 'direct';
      $out[$topic] = $this->key->rewrite($entry, $topic === 'default' && $passedOn ? 'subscribers' : $mode);
      if (!$inherited && $mode === 'subscribers' && self::reachesItsOwn((string) $topic, $out[$topic])) {
        unset($out[$topic]['subscribers_of']);
        $this->key->named[] = (string) $topic;
      }
    }
    $changed = count(array_filter(
      array_keys($map),
      static fn (int|string $topic): bool => $out[$topic] !== $map[$topic],
    ));
    if ($changed > 0) {
      $domain->settings()->set(Router::SETTING, $out);
    }
    return $changed;
  }

  /**
   * @param array<array-key, mixed> $route
   */
  private static function reachesItsOwn(string $topic, array $route): bool {
    return $topic !== 'default' && ($route['subscribers_of'] ?? $topic) === $topic;
  }

}
