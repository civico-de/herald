<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * An old delivery's params with `channel_config` merged in and the channel
 * it implied spelled out: Telegram for direct sends, email for addresses.
 */
final class CRM_Herald_Upgrader_DeliveryParams {

  /**
   * @param array<array-key, mixed> $params
   * @return array<string, mixed>
   */
  public static function normalize(array $params): array {
    $params = self::flattened($params);
    if (($params['parse_mode'] ?? NULL) === '') {
      $params['parse_mode'] = 'text';
    }
    $mode = $params['delivery_mode'] ?? 'direct';
    if (empty($params['channel']) && empty($params['topic']) && in_array($mode, ['direct', ''], TRUE)) {
      $params['channel'] = 'telegram';
    }
    if ($mode === 'addresses') {
      $params['channel'] = 'email';
    }
    return $params;
  }

  /**
   * The params with `channel_config` merged in, nothing else changed.
   *
   * @param array<array-key, mixed> $params
   * @return array<string, mixed>
   */
  public static function flattened(array $params): array {
    $config = $params['channel_config'] ?? NULL;
    $decoded = is_string($config) ? json_decode($config, TRUE) : $config;
    unset($params['channel_config']);
    return array_merge(Cast::namedOnly($params), is_array($decoded) ? Cast::namedOnly($decoded) : []);
  }

}
