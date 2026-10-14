<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * Amounts counted and summed per bucket between edges. Amounts outside
 * caller-supplied edges land in extra "0–low" and "≥high" buckets rather than
 * vanishing; derived edges always cover the data.
 */
final class HistogramBuckets {

  /**
   * @param array<int, float> $amounts
   * @param list<float> $edges At least two, sorted.
   * @return array<int, array{low: float, high: float, count: int, sum: float, label: string}>
   */
  public static function of(array $amounts, array $edges): array {
    $counts = [];
    $sums = [];
    foreach ($amounts as $amount) {
      $slot = self::slot($amount, $edges);
      $counts[$slot] = ($counts[$slot] ?? 0) + 1;
      $sums[$slot] = ($sums[$slot] ?? 0.0) + $amount;
    }
    $last = count($edges) - 1;
    $buckets = [];
    foreach (self::ranges($edges) as $slot => [$low, $high, $label]) {
      // The outer two slots appear only when an amount falls into them.
      $outer = $slot < 0 || $slot === $last;
      if ($outer && !isset($counts[$slot])) {
        continue;
      }
      $buckets[] = [
        'low' => $low,
        'high' => $high,
        'count' => $counts[$slot] ?? 0,
        'sum' => $sums[$slot] ?? 0.0,
        'label' => $label,
      ];
    }
    return $buckets;
  }

  /**
   * Low, high and label per slot, from -1 (below the edges) to the last
   * edge's index (above them).
   *
   * @param list<float> $edges
   * @return array<int, array{float, float, string}>
   */
  private static function ranges(array $edges): array {
    $last = count($edges) - 1;
    // Amounts are positive, so below the edges is 0–edge. No label holds <, > or &: the
    // herald_histogram modifier output goes unescaped into HTML and text alike.
    $ranges = [-1 => [0.0, $edges[0], '0–' . NiceNumber::label($edges[0])]];
    for ($i = 0; $i < $last; $i++) {
      $ranges[$i] = [
        $edges[$i],
        $edges[$i + 1],
        NiceNumber::label($edges[$i]) . '–' . NiceNumber::label($edges[$i + 1]),
      ];
    }
    $ranges[$last] = [$edges[$last], INF, '≥' . NiceNumber::label($edges[$last])];
    return $ranges;
  }

  /**
   * The slot of an amount; the last bucket includes its upper edge.
   *
   * @param list<float> $edges
   */
  private static function slot(float $amount, array $edges): int {
    $last = count($edges) - 1;
    if ($amount < $edges[0]) {
      return -1;
    }
    if ($amount > $edges[$last]) {
      return $last;
    }
    for ($i = 1; $i < $last; $i++) {
      if ($amount < $edges[$i]) {
        return $i - 1;
      }
    }
    return $last - 1;
  }

}
