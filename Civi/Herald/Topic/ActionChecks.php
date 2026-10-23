<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Channel\ChannelStore;
use CRM_Herald_ExtensionUtil as E;

/**
 * The checks of a rule action's params beyond the route parameters: a send
 * through a topic, or a delivery of its own.
 */
final class ActionChecks {

  /**
   * Keys an action may carry next to a topic: what it sends, not where.
   */
  private const BESIDE_TOPIC = ['topic', 'template', 'scope_entity', 'digest', 'vars'];

  public function __construct(
    private readonly ChannelStore $store,
  ) {}

  /**
   * A send through a topic needs a route and takes nothing else of a delivery.
   *
   * @param array<array-key, mixed> $params
   * @return list<string>
   */
  public function topicErrors(mixed $topic, array $params): array {
    $errors = [];
    if (!is_string($topic) || !Router::exists($topic)) {
      $errors[] = E::ts('There is no topic "%1" and no "default" topic.', [
        1 => is_scalar($topic) ? (string) $topic : '',
      ]);
    }
    $extra = array_keys(array_filter(
      array_diff_key($params, array_flip(self::BESIDE_TOPIC)),
      static fn (mixed $value): bool => !in_array($value, [NULL, '', []], TRUE),
    ));
    if ($extra !== []) {
      $errors[] = E::ts('A send through a topic takes its delivery from the topic; remove %1.', [1 => implode(
        ', ',
        $extra,
      )]);
    }
    return $errors;
  }

  /**
   * A delivery of its own names a channel unless it fans out to contacts,
   * and an address list goes through an email channel.
   *
   * @param array<array-key, mixed> $params
   * @return list<string>
   */
  public function channelErrors(array $params): array {
    $mode = $params['delivery_mode'] ?? 'direct';
    $channel = $params['channel'] ?? '';
    if (!in_array($mode, ['direct', 'addresses'], TRUE)) {
      return [];
    }
    if (!is_string($channel) || $channel === '') {
      return [E::ts('Choose a channel.')];
    }
    // An unknown channel is reported by the param checks.
    $type = $this->store->all()[$channel]['type'] ?? NULL;
    return (
      $mode === 'addresses' && $type !== NULL && $type !== 'email'
        ? [E::ts('An address list goes through an email channel; "%1" is not one.', [1 => $channel])]
        : []
    );
  }

}
