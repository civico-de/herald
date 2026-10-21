<?php

declare(strict_types = 1);

use Civi\Herald\Topic\Router;

/**
 * The topic routes in the shape of named channels, each route rewritten as a
 * delivery of its own.
 */
final class CRM_Herald_Upgrader_StoredRoutes {

  private const INHERITED = ['channel', 'webhook_url', 'webhook_auth_token', 'webhook_headers'];

  /**
   * Routes merged shallowly over "default", as the old Router did, so a route's
   * own channel_config replaces the default's and only the merged route lacking
   * a channel means Telegram; a route takes the webhook URL and secrets it inherited.
   *
   * @return int The routes rewritten
   */
  public static function rewrite(CRM_Herald_Upgrader_Domain $domain): int {
    $map = $domain->settings()->get(Router::SETTING);
    if (!is_array($map) || $map === []) {
      return 0;
    }
    $default = is_array($map['default'] ?? NULL) ? $map['default'] : [];
    $owners = [];
    foreach (array_keys($map) as $index => $topic) {
      $owners[$topic] = 'topic_' . ($index + 1);
    }
    $newDefault = isset($map['default'])
      ? CRM_Herald_Upgrader_NamedChannels::delivery(
        CRM_Herald_Upgrader_DeliveryParams::flattened($default),
        $owners['default'],
        [$domain],
      )
      : [];
    $out = [];
    foreach ($map as $topic => $raw) {
      $out[$topic] = $topic === 'default'
        ? $newDefault
        : self::route(is_array($raw) ? $raw : [], $default, $newDefault, $owners[$topic], $domain);
    }
    $domain->settings()->set(Router::SETTING, $out);
    return count($out);
  }

  /**
   * A route with the keys it inherited spelled out, then dropped again where
   * the rewritten default supplies the same value. A key only the flattened
   * default has stays empty, so the channel's own value applies as before.
   *
   * @param array<array-key, mixed> $raw
   * @param array<array-key, mixed> $default
   * @param array<string, mixed> $newDefault
   * @return array<string, mixed>
   */
  private static function route(
    array $raw,
    array $default,
    array $newDefault,
    string $owner,
    CRM_Herald_Upgrader_Domain $domain,
  ): array {
    $route = CRM_Herald_Upgrader_DeliveryParams::flattened($raw);
    $merged = CRM_Herald_Upgrader_DeliveryParams::normalize(array_merge($default, $raw));
    $inherited = array_diff_key(array_intersect_key($merged, array_flip(self::INHERITED)), array_filter($route));
    $rewritten = CRM_Herald_Upgrader_NamedChannels::delivery($route + $inherited, $owner, [$domain]);
    foreach (array_keys($inherited) as $key) {
      if (!array_key_exists($key, $newDefault) || ($rewritten[$key] ?? NULL) !== $newDefault[$key]) {
        continue;
      }
      unset($rewritten[$key]);
    }
    return $rewritten + array_fill_keys(array_keys(array_diff_key($newDefault, $merged, $rewritten)), '');
  }

}
