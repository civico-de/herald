<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use CRM_Herald_ExtensionUtil as E;

/**
 * The checks a change to "default" passes on to the topics it backs.
 */
final class DefaultRules {

  /**
   * Each topic's errors, should "default" get this route.
   *
   * @param array<array-key, mixed> $map
   * @param array<array-key, mixed> $route The route "default" gets
   * @return list<string>
   */
  public static function errors(string $topic, array $map, array $route): array {
    $errors = [];
    foreach ($topic === 'default' ? $map : [] as $name => $entry) {
      if ((string) $name === 'default' || !is_array($entry)) {
        continue;
      }
      $meta = ['label' => '', 'subscribable' => ($entry['subscribable'] ?? FALSE) === TRUE];
      foreach (TopicRules::errors((string) $name, TopicMeta::route($entry), $meta, $route) as $error) {
        $errors[] = E::ts('Topic "%1": %2', [1 => $name, 2 => $error]);
      }
    }
    return $errors;
  }

}
