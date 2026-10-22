<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Subscribers\SubscribableTopics;
use CRM_Herald_ExtensionUtil as E;

/**
 * Which topic's subscribers a delivery reaches.
 */
final class SubscriberRules {

  /**
   * @param array<array-key, mixed> $params
   * @return list<string>
   */
  public static function errors(array $params): array {
    $topic = $params['subscribers_of'] ?? NULL;
    return (
      is_string($topic) && $topic !== '' && !SubscribableTopics::offers($topic)
        ? [E::ts('Contacts cannot subscribe to the topic "%1".', [1 => $topic])]
        : []
    );
  }

  /**
   * A delivery of its own to subscribers names the topic; a route has its own.
   *
   * @param array<array-key, mixed> $params
   * @return list<string>
   */
  public static function ownDeliveryErrors(array $params): array {
    return (
      ($params['delivery_mode'] ?? NULL) === 'subscribers' && empty($params['subscribers_of'])
        ? [E::ts('Choose the topic whose subscribers to reach.')]
        : []
    );
  }

}
