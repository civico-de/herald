<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Subscribers\GroupRecipients;
use Civi\Herald\Subscribers\SubscriberLookup;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Sends a rendered body the way `delivery_mode` says: through the named
 * channel to its recipient, to a subscription type's or a group's contacts,
 * or through the named email channel to an address list.
 */
final class Delivery {

  /**
   * @param array<string, mixed> $params
   */
  public static function send(
    string $body,
    array $params,
    ?SubscriberLookup $lookup = NULL,
    ?ChannelSender $sender = NULL,
  ): void {
    $sender ??= new ChannelSender();
    match ($params['delivery_mode'] ?? 'direct') {
      'subscribers' => FanOut::toRecipients(
        $body,
        $params,
        self::subscribers($params, $lookup ?? new SubscriberLookup()),
        $sender,
      ),
      'group' => FanOut::toRecipients($body, $params, self::group($params), $sender),
      'addresses' => FanOut::toAddresses($body, $params, $sender),
      'direct' => $sender->send(self::channel($params), $body, ChannelConfig::of($params)),
      default => throw new \CRM_Core_Exception(sprintf(
        'herald: unknown delivery_mode "%s"; use direct, subscribers, group or addresses.',
        Cast::toString($params['delivery_mode']),
      )),
    };
  }

  /**
   * The channel a direct or address delivery goes through.
   *
   * @param array<string, mixed> $params
   */
  public static function channel(array $params): string {
    $name = Cast::toString($params['channel'] ?? '');
    if ($name === '') {
      throw new \CRM_Core_Exception('herald: the delivery names no channel.');
    }
    return $name;
  }

  /**
   * Fan-out options from the caller's params.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function options(array $params): array {
    $options = [
      'respect_suspension' => !empty($params['respect_suspension']),
      'location_type_id' => Cast::toInt($params['email_location_type'] ?? 0),
    ];
    $scopeType = Cast::toString($params['scope_entity_type'] ?? '');
    $scopeId = Cast::toInt($params['scope_entity_id'] ?? 0);
    if ($scopeType !== '' && $scopeId > 0) {
      $options['scope'] = ['entity_type' => $scopeType, 'entity_id' => $scopeId];
    }
    return $options;
  }

  /**
   * @param array<string, mixed> $params
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  private static function subscribers(array $params, SubscriberLookup $lookup): array {
    $subscriptionType = Cast::toString($params['subscription_type'] ?? '');
    if ($subscriptionType === '') {
      throw new NoRecipientsException('herald: subscriber-mode requires action parameter "subscription_type".');
    }
    return $lookup->findBySubscriptionType($subscriptionType, self::options($params));
  }

  /**
   * @param array<string, mixed> $params
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  private static function group(array $params): array {
    $groupId = Cast::toInt($params['group_id'] ?? 0);
    if ($groupId <= 0) {
      throw new NoRecipientsException('herald: group mode requires action parameter "group_id".');
    }
    return (new GroupRecipients())->findByGroup($groupId, self::options($params));
  }

}
