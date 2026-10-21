<?php

declare(strict_types = 1);

use Civi\Herald\Channel\PreferredChannelOptions;

/**
 * The channel settings become channels named like their type, and every
 * stored delivery names one: `channel_config` is merged into the params, a
 * webhook token of a delivery moves into a channel of its own.
 */
final class CRM_Herald_Upgrader_NamedChannels {

  /**
   * @return array{channels: int, deliveries: int}
   */
  public static function migrate(): array {
    $channels = 0;
    $domains = [];
    foreach (CRM_Herald_Upgrader_OldSettings::read() as $domainId => $values) {
      $channels += CRM_Herald_Upgrader_SettingChannels::create($domainId, $values);
      $domains[] = CRM_Herald_Upgrader_Domain::of($domainId, $values);
    }
    $deliveries = CRM_Herald_Upgrader_StoredDeliveries::rewrite($domains);
    CRM_Herald_Upgrader_OldSettings::deleteOld();
    CRM_Herald_Upgrader_PreferredChannelField::repoint();
    PreferredChannelOptions::sync();
    return ['channels' => $channels, 'deliveries' => $deliveries];
  }

  /**
   * One delivery in the new shape. Old deliveries without a channel went to Telegram.
   *
   * @param array<array-key, mixed> $params
   * @param string $owner Unique suffix for a channel of the delivery's own
   * @param list<CRM_Herald_Upgrader_Domain> $domains Where the delivery may be sent from
   * @return array<string, mixed>
   */
  public static function delivery(array $params, string $owner, array $domains): array {
    return CRM_Herald_Upgrader_WebhookSecrets::move(
      CRM_Herald_Upgrader_DeliveryParams::normalize($params),
      $owner,
      $domains,
    );
  }

}
