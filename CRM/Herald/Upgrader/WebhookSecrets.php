<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ConfigSpec;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The webhook token and headers that sat beside a delivery's URL: they leave
 * the params and live in a webhook channel, shared by deliveries with equal ones.
 */
final class CRM_Herald_Upgrader_WebhookSecrets {

  /**
   * Keys that sat beside a delivery's URL and become a channel of its own.
   */
  private const KEYS = ['webhook_auth_token', 'webhook_headers'];

  /**
   * The channel is created in every domain the delivery may be sent from,
   * each with the URL that domain sent to. Other channel keys were never
   * read from a delivery and are dropped.
   *
   * @param array<string, mixed> $params
   * @param string $owner Unique suffix for a channel of the delivery's own
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @return array<string, mixed>
   */
  public static function move(array $params, string $owner, array $domains): array {
    $secrets = self::secrets($params);
    $configs =
      $secrets === [] || ($params['channel'] ?? NULL) !== 'webhook'
        ? []
        : CRM_Herald_Upgrader_WebhookConfigs::of($params, $secrets, $domains);
    $params = array_diff_key($params, array_flip(ChannelRegistry::default()->channelKeys()));
    // Without any URL the secrets were never sent.
    if ($configs === []) {
      return $params;
    }
    [$configs, $rejected] = CRM_Herald_Upgrader_WebhookConfigs::fitting($configs);
    $name = CRM_Herald_Upgrader_SharedWebhook::name($domains, $configs, $rejected) ?? 'webhook_' . $owner;
    CRM_Herald_Upgrader_SharedWebhook::create($domains, $name, $configs, $rejected);
    $params['channel'] = $name;
    return $params;
  }

  /**
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  private static function secrets(array $params): array {
    $secrets = array_filter(
      array_intersect_key($params, array_flip(self::KEYS)),
      static fn (mixed $value): bool => !ConfigSpec::blank(is_string($value) ? trim($value) : $value),
    );
    // The old channel sent the token as text.
    if (isset($secrets['webhook_auth_token']) && is_scalar($secrets['webhook_auth_token'])) {
      $secrets['webhook_auth_token'] = Cast::toString($secrets['webhook_auth_token']);
    }
    return $secrets;
  }

}
