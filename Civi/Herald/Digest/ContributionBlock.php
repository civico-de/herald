<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The checks only a contribution block has.
 */
final class ContributionBlock {

  /**
   * @param array<string, mixed> $block HeraldDigestBlock values
   */
  public static function problem(array $block): ?string {
    $tooFewBuckets = isset($block['bucket_count']) && Cast::toInt($block['bucket_count']) < 1;
    return (
      Options::problem(E::ts('grouping'), $block['group_by'] ?? NULL, Options::groupings())
      ?? ($tooFewBuckets ? E::ts('the bucket count must be at least 1.') : NULL)
      // Edges of a block without histogram are never read.
      ?? (!empty($block['show_histogram'] ?? TRUE) ? BucketEdges::problem($block['bucket_edges'] ?? NULL) : NULL)
    );
  }

}
