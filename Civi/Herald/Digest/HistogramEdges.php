<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * Bucket edges derived from the data, so €1–€50 donations and a €100–€100k
 * campaign both get sensible buckets without tuning.
 */
final class HistogramEdges {

  /**
   * Log-space buckets once max/min spans more than 10^2.
   */
  private const LOG_SPAN_THRESHOLD = 2.0;

  /**
   * @param non-empty-array<int, float> $amounts Positive amounts.
   * @return float[] Sorted edges.
   */
  public static function derive(array $amounts, int $bucketCount): array {
    $count = count($amounts);
    $min = min($amounts);
    $max = max($amounts);
    if ($max <= $min) {
      return [NiceNumber::floor($min), NiceNumber::ceil($max + 1)];
    }
    if ($count < ($bucketCount * 2)) {
      return self::linear($min, $max, min($bucketCount, max(2, intdiv($count, 2))));
    }
    if ((log10($max) - log10(max($min, 1))) > self::LOG_SPAN_THRESHOLD) {
      return self::logarithmic($min, $max, $bucketCount);
    }
    return self::linear($min, $max, $bucketCount);
  }

  /**
   * Equal steps of a 1-2-5 size, at most $count buckets; the first edge is the
   * nice floor of $min, the others are multiples of the step.
   *
   * @return float[]
   */
  private static function linear(float $min, float $max, int $count): array {
    $low = NiceNumber::floor($min);
    $step = NiceNumber::ceil(($max - $low) / $count);
    $edges = self::stepped($low, $max, $step);
    while ((count($edges) - 1) > $count) {
      $step = NiceNumber::ceil($step * 1.01);
      $edges = self::stepped($low, $max, $step);
    }
    return $edges;
  }

  /**
   * $low, then every multiple of $step up to the first one at or above $max.
   * Rounded, so 3 × 0.1 is the edge 0.3 and not 0.30000000000000004.
   *
   * @return float[]
   */
  private static function stepped(float $low, float $max, float $step): array {
    $edges = [$low];
    $last = (int) ceil(($max / $step) - 1e-9);
    for ($k = (int) floor(($low / $step) + 1e-9) + 1; $k <= $last; $k++) {
      $edges[] = round($k * $step, 10);
    }
    return $edges;
  }

  /**
   * @return float[]
   */
  private static function logarithmic(float $min, float $max, int $count): array {
    $logMin = log10(max($min, 1));
    $step = (log10($max) - $logMin) / $count;
    $edges = [NiceNumber::floor(max($min, 1))];
    for ($i = 1; $i < $count; $i++) {
      $edges[] = NiceNumber::ceil(10 ** ($logMin + ($step * $i)));
    }
    $edges[] = NiceNumber::ceil($max);
    return array_values(array_unique($edges, SORT_NUMERIC));
  }

}
