<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ConfigSpec;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The config of the webhook channel a delivery's secrets move into, per domain.
 */
final class CRM_Herald_Upgrader_WebhookConfigs {

  /**
   * Domain id => the channel config the delivery was sent with from there.
   *
   * @param array<string, mixed> $params
   * @param array<string, mixed> $secrets
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @return array<int, array<string, mixed>>
   */
  public static function of(array $params, array $secrets, array $domains): array {
    $headers = $secrets['webhook_headers'] ?? NULL;
    if (is_string($headers)) {
      $decoded = json_decode($headers, TRUE);
      $secrets['webhook_headers'] = is_array($decoded) ? $decoded : $headers;
    }
    $configs = [];
    foreach ($domains as $domain) {
      $url = Cast::toString($params['webhook_url'] ?? '') ?: $domain->defaultUrl;
      if ($url === '') {
        continue;
      }
      // The default token only ever went with the default URL.
      $token = empty($params['webhook_url']) && empty($secrets['webhook_auth_token']) ? $domain->defaultToken : '';
      $configs[$domain->id] = $secrets + array_filter(['webhook_auth_token' => $token]) + ['webhook_url' => $url];
    }
    return $configs;
  }

  /**
   * Values the webhook schema rejects, such as headers that are no JSON
   * object, failed every send before: the channel leaves them out.
   *
   * @param array<int, array<string, mixed>> $configs Domain id => config
   * @return array{array<int, array<string, mixed>>, list<string>} The configs and the errors
   */
  public static function fitting(array $configs): array {
    $schema = ChannelRegistry::default()->get('webhook')->getConfigSchema();
    $errors = [];
    foreach ($configs as $domainId => $config) {
      foreach ($config as $key => $value) {
        $error = ConfigSpec::valueError('webhook', $key, $schema[$key] ?? NULL, $value);
        if ($error === NULL) {
          continue;
        }
        $errors[] = $error;
        unset($configs[$domainId][$key]);
      }
    }
    return [$configs, array_values(array_unique($errors))];
  }

}
