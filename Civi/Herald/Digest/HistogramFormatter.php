<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * ASCII histogram of amounts, with buckets from HistogramEdges or the caller.
 */
class HistogramFormatter {

  /**
   * Build histogram buckets from raw amounts.
   *
   * @param float[] $amounts
   * @param int $bucketCount Target number of buckets. Actual count may be lower
   *   when several edges collapse to the same nice number.
   * @param float[]|null $overrideEdges Caller-supplied edges, at least two (skips adaptation).
   *
   * @return array<int, array{low: float, high: float, count: int, sum: float, label: string}>
   */
  public static function compute(array $amounts, int $bucketCount = 8, ?array $overrideEdges = NULL): array {
    $amounts = array_values(array_filter($amounts, static fn ($a) => $a > 0));
    if ($amounts === []) {
      return [];
    }
    if ($overrideEdges !== NULL && count($overrideEdges) < 2) {
      throw new \InvalidArgumentException('herald: fewer than two histogram edges define no bucket');
    }
    return HistogramBuckets::of(
      $amounts,
      array_values($overrideEdges ?? HistogramEdges::derive($amounts, $bucketCount)),
    );
  }

  /**
   * Render a histogram as ASCII bars (Unicode block characters).
   *
   * @param array<int, array{low: float, high: float, count: int, sum: float, label: string}> $buckets
   *   Output of compute().
   * @param int $maxBlocks Max bar width in characters.
   */
  public static function render(array $buckets, int $maxBlocks = 12): string {
    $maxCount = max([0, ...array_column($buckets, 'count')]);
    if ($maxCount === 0) {
      return '(no data)';
    }
    $labelWidth = max([0, ...array_map(static fn ($b) => mb_strlen($b['label']), $buckets)]);
    $lines = [];
    foreach ($buckets as $bucket) {
      $lines[] = sprintf(
        '%s %s %d (%s)',
        $bucket['label'] . str_repeat(' ', $labelWidth - mb_strlen($bucket['label'])),
        str_repeat('█', (int) round(($bucket['count'] / $maxCount) * $maxBlocks)),
        $bucket['count'],
        NiceNumber::label($bucket['sum']),
      );
    }
    return implode("\n", $lines);
  }

}
