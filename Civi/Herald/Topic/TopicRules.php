<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Subscribers\Subscriptions;
use CRM_Herald_ExtensionUtil as E;

/**
 * The checks of a topic's own keys, label and subscribable, against its route.
 */
final class TopicRules {

  /**
   * @param array<array-key, mixed> $route
   * @param array{label: string, subscribable: bool} $meta
   * @param array<array-key, mixed> $default The route of "default", which backs this one
   * @return list<string>
   */
  public static function errors(string $topic, array $route, array $meta, array $default): array {
    $errors = [];
    $named = array_values(array_intersect(array_keys($route), TopicMeta::KEYS));
    if ($named !== []) {
      $errors[] = E::ts('%1 belong to the topic, not to its route.', [1 => implode(', ', $named)]);
    }
    if ($topic === 'default' && $meta['subscribable']) {
      $errors[] = E::ts('Contacts cannot subscribe to "default"; it only backs the other topics.');
    }
    $merged = $topic === 'default' ? $route : array_merge($default, $route);
    $toOwn =
      ($merged['delivery_mode'] ?? NULL) === 'subscribers'
      && TopicMeta::subscribersOf($topic, $route + ['subscribable' => $meta['subscribable']], $merged) === $topic;
    if ($toOwn && $topic !== 'default' && !$meta['subscribable']) {
      $errors[] = E::ts(
        'A route to subscribers reaches the contacts who subscribed to this topic; let contacts subscribe to it.',
      );
    }
    return $errors;
  }

  /**
   * A route naming its own topic's subscribers keeps naming them under the new name.
   *
   * @param array<array-key, mixed> $route
   * @return array<array-key, mixed>
   */
  public static function renamed(array $route, string $previous, string $topic): array {
    if ($previous !== '' && ($route['subscribers_of'] ?? NULL) === $previous) {
      $route['subscribers_of'] = $topic;
    }
    return $route;
  }

  /**
   * A rename would leave the subscriptions of contacts on the old name.
   *
   * @return list<string>
   */
  public static function renameErrors(string $previous): array {
    $subscribers = Subscriptions::subscribers($previous);
    return (
      $subscribers > 0
        ? [E::ts('The topic "%1" cannot be renamed while %2 contacts subscribe to it.', [
          1 => $previous,
          2 => $subscribers,
        ])]
        : []
    );
  }

}
