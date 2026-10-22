<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Admin\ParamsInUse;
use CRM_Herald_ExtensionUtil as E;

/**
 * A topic that stops offering itself leaves no delivery without its subscribers.
 */
final class WithdrawRules {

  /**
   * A rename is checked for users of the stored name on its own.
   *
   * @param array{label: string, subscribable: bool} $meta
   * @param array<array-key, mixed> $route
   * @param array<array-key, mixed> $default The route of "default"
   * @return list<string>
   */
  public static function errors(string $topic, mixed $stored, array $meta, array $route, array $default): array {
    $withdrawn = is_array($stored) && ($stored['subscribable'] ?? NULL) === TRUE && !$meta['subscribable'];
    return $withdrawn ? [...self::inUse($topic), ...self::redirected($topic, $route, $default)] : [];
  }

  /**
   * @return list<string>
   */
  private static function inUse(string $topic): array {
    $users = ParamsInUse::ofSubscribers($topic);
    return (
      $users === []
        ? []
        : [E::ts('Contacts must be able to subscribe to "%1" while it is in use:', [1 => $topic]), ...$users]
    );
  }

  /**
   * Withdrawn, the route would reach the subscribers "default" names instead.
   *
   * @param array<array-key, mixed> $route
   * @param array<array-key, mixed> $default
   * @return list<string>
   */
  private static function redirected(string $topic, array $route, array $default): array {
    $merged = array_merge($default, $route);
    $to = TopicMeta::subscribersOf($topic, $route, $merged);
    $reachedOwn = TopicMeta::subscribersOf($topic, $route + ['subscribable' => TRUE], $merged) === $topic;
    return (
      ($merged['delivery_mode'] ?? NULL) === 'subscribers' && $reachedOwn && $to !== $topic
        ? [E::ts(
          'Without subscriptions, "%1" would reach the subscribers of "%2"; name them in its route as subscribers_of to keep that.',
          [1 => $topic, 2 => $to],
        )]
        : []
    );
  }

}
