<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Channel\ChannelRegistry;
use CRM_Herald_ExtensionUtil as E;

/**
 * The typed keys a topic route can carry: the dispatcher's delivery
 * parameters plus the delivery keys of every channel type's getConfigSchema().
 */
final class RouteFields {

  public const DELIVERY_MODES = ['direct', 'subscribers', 'group', 'addresses'];

  private const DELIVERY_TYPES = [
    'channel' => 'string',
    'delivery_mode' => 'enum',
    'subscribers_of' => 'string',
    'group_id' => 'int',
    'email_addresses' => 'string',
    'respect_suspension' => 'bool',
    'email_location_type' => 'int',
    'aggregate_minutes' => 'int',
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
        if (($spec['scope'] ?? 'delivery') === 'channel') {
          continue;
        }
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
      'subscribers_of' => E::ts('Subscribers of topic'),
      'group_id' => E::ts('Group'),
      'email_addresses' => E::ts('Email addresses'),
      'respect_suspension' => E::ts('Respect communication suspension'),
      'email_location_type' => E::ts('Email location type'),
      'aggregate_minutes' => E::ts('Aggregate window in minutes'),
    ];
  }

}
