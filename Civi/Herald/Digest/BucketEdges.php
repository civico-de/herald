<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use CRM_Herald_ExtensionUtil as E;

/**
 * Fixed histogram edges, as the herald_histogram modifier takes them: "0,10,50,250".
 */
final class BucketEdges {

  /**
   * @return list<float>
   * @throws DigestProblem when the text is not at least two ascending numbers
   */
  public static function parse(string $raw): array {
    $edges = [];
    foreach (explode(',', $raw) as $part) {
      $part = trim($part);
      if (!is_numeric($part) || $edges !== [] && (float) $part <= end($edges)) {
        throw new DigestProblem([E::ts('herald_histogram: the edges "%1" must be ascending numbers, e.g. "0,10,50".', [
          1 => $raw,
        ])]);
      }
      $edges[] = (float) $part;
    }
    if (count($edges) < 2) {
      throw new DigestProblem([E::ts('herald_histogram: the edges "%1" must be at least two numbers, e.g. "0,10,50".', [
        1 => $raw,
      ])]);
    }
    return $edges;
  }

}
