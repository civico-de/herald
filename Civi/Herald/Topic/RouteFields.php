<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Channel\ChannelRegistry;
use CRM_Herald_ExtensionUtil as E;

/**
 * The typed keys a topic route can carry: the dispatcher's delivery
 * parameters plus every channel's getConfigSchema() keys.
 */
final class RouteFields {

  public const DELIVERY_MODES = ['direct', 'subscribers', 'group', 'addresses'];

  private const DELIVERY_TYPES = [
    'channel' => 'string',
    'delivery_mode' => 'enum',
    'subscription_type' => 'string',
    'group_id' => 'int',
    'email_addresses' => 'string',
    'respect_suspension' => 'bool',
    'email_location_type' => 'int',
    'aggregate_minutes' => 'int',
    'channel_config' => 'object',
  ];

  /**
   * @return array<string, array{label: string, type: string, options: list<string>}>
   */
  public static function all(ChannelRegistry $registry): array {
    $labels = self::deliveryLabels();
    $fields = [];
    foreach (self::DELIVERY_TYPES as $key => $type) {
      $fields[$key] = [
        'label' => $labels[$key],
        'type' => $type,
        'options' => $key === 'delivery_mode' ? self::DELIVERY_MODES : [],
      ];
    }
    foreach ($registry->listKeys() as $channelKey) {
      foreach ($registry->get($channelKey)->getConfigSchema() as $key => $spec) {
        $options = $spec['options'] ?? [];
        $fields[$key] ??= [
          'label' => $spec['label'],
          'type' => $spec['type'],
          'options' => array_map('strval', array_is_list($options) ? $options : array_keys($options)),
        ];
      }
    }
    return $fields;
  }

  /**
   * @return array<string, string>
   */
  private static function deliveryLabels(): array {
    return [
      'channel' => E::ts('Channel'),
      'delivery_mode' => E::ts('Delivery mode'),
      'subscription_type' => E::ts('Subscription type'),
      'group_id' => E::ts('Group'),
      'email_addresses' => E::ts('Email addresses'),
      'respect_suspension' => E::ts('Respect communication suspension'),
      'email_location_type' => E::ts('Email location type'),
      'aggregate_minutes' => E::ts('Aggregate window in minutes'),
      'channel_config' => E::ts('Other parameters'),
    ];
  }

}
