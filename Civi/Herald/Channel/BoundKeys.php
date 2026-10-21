<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Keys a channel sends only together with another (bound_to).
 */
final class BoundKeys {

  /**
   * A key bound to another (a token to its URL) is sent only with that key.
   *
   * @param array<string, array<string, mixed>> $schema
   * @param array<string, mixed> $out
   * @return list<string>
   */
  public static function errors(array $schema, array $out): array {
    $errors = [];
    foreach (array_intersect_key($schema, $out) as $key => $spec) {
      $anchor = $spec['bound_to'] ?? NULL;
      if (!is_string($anchor) || isset($out[$anchor])) {
        continue;
      }
      $errors[] = E::ts('"%1" is sent only to the channel\'s "%2"; set "%2" as well.', [
        1 => Cast::toString($spec['label'] ?? $key),
        2 => Cast::toString($schema[$anchor]['label'] ?? $anchor),
      ]);
    }
    return $errors;
  }

}
