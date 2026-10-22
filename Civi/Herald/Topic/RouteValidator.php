<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ChannelStore;
use CRM_Herald_ExtensionUtil as E;

/**
 * Checks one herald_topics entry before the admin screen stores it, and the
 * delivery of a rule action. Keys nobody declares pass unchecked, as the
 * channels ignore them too.
 */
final class RouteValidator {

  private ChannelRegistry $registry;

  private ChannelStore $store;

  private ActionChecks $actionChecks;

  public function __construct(?ChannelRegistry $registry = NULL, ?ChannelStore $store = NULL) {
    $this->registry = $registry ?? ChannelRegistry::default();
    $this->store = $store ?? new ChannelStore($this->registry);
    $this->actionChecks = new ActionChecks($this->store);
  }

  /**
   * The checks of a rule action's or job's params: a topic, or a delivery of its own.
   *
   * @param array<array-key, mixed> $params
   * @return list<string>
   */
  public function actionErrors(array $params): array {
    $topic = $params['topic'] ?? '';
    if ($topic === '') {
      return array_merge(
        $this->paramErrors($params),
        $this->actionChecks->channelErrors($params),
        SubscriberRules::ownDeliveryErrors($params),
      );
    }
    return $this->actionChecks->topicErrors($topic, $params);
  }

  /**
   * @param array<array-key, mixed> $route
   * @return list<string>
   */
  public function errors(string $topic, array $route): array {
    $errors =
      $topic === '' || trim($topic) !== $topic
        ? [E::ts('Enter a topic name without leading or trailing spaces.')]
        : [];
    // Whether a topic offers itself is TopicRules' check, against the form.
    $own =
      $topic !== 'default' && ($route['subscribers_of'] ?? NULL) === $topic
        ? array_diff_key($route, ['subscribers_of' => TRUE])
        : $route;
    return array_merge($errors, $this->paramErrors($own));
  }

  /**
   * The checks of a route's parameters, which a rule action's take too.
   *
   * @param array<array-key, mixed> $params
   * @return list<string>
   */
  public function paramErrors(array $params): array {
    $errors = [];
    $channel = $params['channel'] ?? NULL;
    if (is_string($channel) && $channel !== '' && !array_key_exists($channel, $this->store->all())) {
      $errors[] = E::ts('There is no channel "%1".', [1 => $channel]);
    }
    $channelKeys = array_values(array_intersect(array_keys($params), $this->registry->channelKeys()));
    if ($channelKeys !== []) {
      $errors[] = E::ts('%1 belong to the channel, not to a delivery; set them under Channels.', [1 => implode(
        ', ',
        $channelKeys,
      )]);
    }
    $fields = RouteFields::all($this->registry);
    foreach (array_intersect_key($params, $fields) as $key => $value) {
      $errors[] = ValueType::error($fields[$key], $value);
    }
    return array_values(array_filter(array_merge(
      $errors,
      SubscriberRules::errors($params),
      RouteRules::errors($params, $fields),
    )));
  }

}
