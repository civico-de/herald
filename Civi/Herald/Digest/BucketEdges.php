<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Fixed histogram edges of a contribution block (`bucket_edges`).
 */
final class BucketEdges {

  /**
   * The edges, or NULL for edges derived from the data.
   *
   * @return list<float>|null
   * @throws \InvalidArgumentException when the value is not a JSON array of ascending numbers
   */
  public static function parse(mixed $raw): ?array {
    if ($raw === NULL || is_string($raw) && trim($raw) === '') {
      return NULL;
    }
    $decoded = is_array($raw) ? $raw : json_decode(Cast::toString($raw), TRUE);
    if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) < 2) {
      throw new \InvalidArgumentException(E::ts(
        'the bucket edges must be a JSON array of at least two numbers, e.g. [0, 10, 50].',
      ));
    }
    $edges = [];
    foreach ($decoded as $edge) {
      if (!is_int($edge) && !is_float($edge) || $edges !== [] && $edge <= end($edges)) {
        throw new \InvalidArgumentException(E::ts('the bucket edges must be numbers in ascending order.'));
      }
      $edges[] = (float) $edge;
    }
    return $edges;
  }

  /**
   * Why the value cannot be parsed, or NULL.
   */
  public static function problem(mixed $raw): ?string {
    try {
      self::parse($raw);
      return NULL;
    }
    catch (\InvalidArgumentException $e) {
      return $e->getMessage();
    }
  }

}
