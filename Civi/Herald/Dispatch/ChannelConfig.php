<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Channel\ChannelInterface;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The config one send gets: the configured channel's keys with the
 * delivery's keys on top, as the channel types' schemas allow.
 */
final class ChannelConfig {

  /**
   * The delivery keys of a caller's params: everything but the routing keys.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function of(array $params): array {
    unset($params['channel'], $params['template'], $params['topic']);
    return Cast::namedOnly($params);
  }

  /**
   * Reduce a delivery to the presentation keys a subscriber's own channel may
   * take: no recipient keys and no channel keys of any type.
   *
   * @param array<array-key, mixed> $config
   * @return array<string, mixed>
   */
  public static function subscriberSafe(array $config, ?ChannelRegistry $registry = NULL): array {
    $safe = [ChannelSubject::KEY => TRUE];
    $registry ??= ChannelRegistry::default();
    foreach ($registry->listKeys() as $type) {
      foreach ($registry->get($type)->getConfigSchema() as $key => $spec) {
        if (!empty($spec['recipient']) || ($spec['scope'] ?? 'delivery') !== 'delivery') {
          continue;
        }
        $safe[$key] = TRUE;
      }
    }
    return Cast::namedOnly(array_intersect_key($config, $safe));
  }

  /**
   * Merge a delivery over a channel's stored config. Empty delivery values
   * leave the channel's default; a delivery to its own address drops the
   * channel keys bound to the channel's address (bound_to), and a rendered
   * subject beats the channel's default subject.
   *
   * @param array<string, mixed> $stored
   * @param array<string, mixed> $delivery
   * @return array<string, mixed>
   */
  public static function merged(ChannelInterface $channel, array $stored, array $delivery): array {
    $named = array_values(array_intersect(array_keys($delivery), ChannelRegistry::default()->channelKeys()));
    if ($named !== []) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: %s belong to the channel configuration, not to a delivery.',
        implode(', ', $named),
      ));
    }
    $delivery = array_filter($delivery, static fn (mixed $value): bool => $value !== NULL && $value !== '');
    foreach ($channel->getConfigSchema() as $key => $spec) {
      $anchor = $spec['bound_to'] ?? NULL;
      if ($anchor !== NULL && isset($delivery[$anchor]) && $delivery[$anchor] !== ($stored[$anchor] ?? NULL)) {
        unset($stored[$key]);
      }
    }
    if (!empty($delivery[ChannelSubject::KEY])) {
      unset($stored[$channel->getSemanticFields()['subject'] ?? '']);
    }
    return ChannelSubject::forChannel($channel, array_merge($stored, $delivery));
  }

}
