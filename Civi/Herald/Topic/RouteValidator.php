<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Channel\ChannelRegistry;
use CRM_Herald_ExtensionUtil as E;

/**
 * Checks one herald_topics entry before the admin screen stores it. Keys
 * nobody declares pass unchecked, as Router ignores them too.
 */
final class RouteValidator {

  private ChannelRegistry $registry;

  public function __construct(?ChannelRegistry $registry = NULL) {
    $this->registry = $registry ?? ChannelRegistry::default();
  }

  /**
   * @param array<array-key, mixed> $route
   * @return list<string>
   */
  public function errors(string $topic, array $route): array {
    $errors = [];
    if ($topic === '' || trim($topic) !== $topic) {
      $errors[] = E::ts('Enter a topic name without leading or trailing spaces.');
    }
    $channel = $route['channel'] ?? NULL;
    if (is_string($channel) && !$this->registry->has($channel)) {
      $errors[] = E::ts('The channel "%1" is not registered.', [1 => $channel]);
    }
    $fields = RouteFields::all($this->registry);
    foreach (array_intersect_key($route, $fields) as $key => $value) {
      $errors[] = ValueType::error($fields[$key], $value);
    }
    return array_values(array_filter(array_merge($errors, RouteRules::errors($route, $fields))));
  }

}
