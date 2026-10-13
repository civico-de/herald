<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use CRM_Herald_ExtensionUtil as E;

/**
 * Route rules beyond value types: minimums, and the parameter a delivery mode needs.
 */
final class RouteRules {

  /**
   * What each delivery mode cannot do without.
   */
  private const MODE_NEEDS = [
    'subscribers' => 'subscription_type',
    'group' => 'group_id',
    'addresses' => 'email_addresses',
  ];

  private const MINIMUMS = ['aggregate_minutes' => 0, 'group_id' => 1];

  /**
   * @param array<array-key, mixed> $route
   * @param array<string, array{label: string, type: string, options: list<string>}> $fields
   * @return list<string>
   */
  public static function errors(array $route, array $fields): array {
    $errors = [];
    foreach (self::MINIMUMS as $key => $min) {
      if (!is_numeric($route[$key] ?? NULL) || (int) $route[$key] >= $min) {
        continue;
      }
      $errors[] = E::ts('"%1" must be at least %2.', [1 => $fields[$key]['label'], 2 => $min]);
    }
    $mode = $route['delivery_mode'] ?? NULL;
    $need = is_string($mode) ? self::MODE_NEEDS[$mode] ?? NULL : NULL;
    if ($need !== NULL && empty($route[$need])) {
      $errors[] = E::ts('The delivery mode "%1" needs "%2".', [1 => $mode, 2 => $fields[$need]['label']]);
    }
    return $errors;
  }

}
