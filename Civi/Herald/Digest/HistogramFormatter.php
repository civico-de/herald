<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * Adaptive ASCII histogram for an array of numeric amounts.
 *
 * Bucket edges are derived from the data, not configured upfront — a small NGO
 * with €1–€50 contributions and a major-donor campaign with €100–€100k both get
 * sensible buckets without admin tuning.
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
class HistogramFormatter {

  /**
   * @var int[] Mantissas used to round edges to "nice" numbers (1-2-5 series). */
  private const NICE_MANTISSAS = [1, 2, 5];

  /**
   * Switch from linear to log-space buckets when max/min exceeds 100×. */
  private const LOG_SPAN_THRESHOLD = 2.0;

  /**
   * Build histogram buckets from raw amounts.
   *
   * @param float[] $amounts
   * @param int $bucketCount Target number of buckets. Actual count may be lower
   *   when several edges collapse to the same nice number.
   * @param float[]|null $overrideEdges Caller-supplied edges (skips adaptation).
   *
   * @return array<int, array{low: float, high: float, count: int, sum: float, label: string}>
   */
  public static function compute(array $amounts, int $bucketCount = 8, ?array $overrideEdges = NULL): array {
    $amounts = array_values(array_filter($amounts, static fn ($a) => $a > 0));
    if (empty($amounts)) {
      return [];
    }

    // Fewer than two edges defines no bucket; the form decodes '[]' to an
    // empty array, so treat that like an absent override.
    if ($overrideEdges !== NULL && count($overrideEdges) >= 2) {
      $edges = $overrideEdges;
    }
    // @mago-expect lint:no-else-clause
    else {
      $edges = self::deriveEdges($amounts, $bucketCount);
    }

    return self::distribute($amounts, $edges);
  }

  /**
   * Render a histogram as ASCII bars (Unicode block characters).
   *
   * @param array<int, array{low: float, high: float, count: int, sum: float, label: string}> $buckets
   *   Output of compute().
   * @param int $maxBlocks Max bar width in characters.
   */
  public static function render(array $buckets, int $maxBlocks = 12): string {
    if (empty($buckets)) {
      return '(no data)';
    }
    $maxCount = max(array_column($buckets, 'count'));
    if ($maxCount === 0) {
      return '(no data)';
    }

    $labelWidth = max(array_map(static fn ($b) => mb_strlen($b['label']), $buckets));

    $lines = [];
    foreach ($buckets as $bucket) {
      $blocks = (int) round(($bucket['count'] / $maxCount) * $maxBlocks);
      $bar = str_repeat('█', max(0, $blocks));
      $label = self::padLabel($bucket['label'], $labelWidth);
      $lines[] = sprintf('%s %s %d (%s)', $label, $bar, $bucket['count'], self::formatNumber($bucket['sum']));
    }
    return implode("\n", $lines);
  }

  /**
   * Decide bucket edges from the data.
   *
   * @param non-empty-array<int, float> $amounts
   *
   * @return float[] sorted edges
   */
  private static function deriveEdges(array $amounts, int $bucketCount): array {
    $count = count($amounts);
    $min = min($amounts);
    $max = max($amounts);

    if ($max <= $min) {
      return [self::niceFloor($min), self::niceCeiling($max + 1)];
    }

    if ($count < ($bucketCount * 2)) {
      return self::linearEdges($min, $max, min($bucketCount, max(2, intdiv($count, 2))));
    }

    $logSpan = log10($max) - log10(max($min, 1));
    if ($logSpan > self::LOG_SPAN_THRESHOLD) {
      return self::logEdges($min, $max, $bucketCount);
    }
    return self::linearEdges($min, $max, $bucketCount);
  }

  /**
   * Equal steps of a 1-2-5 size, at most $count buckets; the first edge is the
   * nice floor of $min, the others are multiples of the step.
   *
   * @return float[]
   */
  private static function linearEdges(float $min, float $max, int $count): array {
    $low = self::niceFloor($min);
    $step = self::niceCeiling(($max - $low) / $count);
    while ((int) ceil((($max - $low) / $step) - 1e-9) > $count) {
      $step = self::niceCeiling($step * 1.01);
    }

    $edges = [$low];
    for ($edge = (floor($low / $step) + 1) * $step; $edge < ($max - 1e-9); $edge += $step) {
      $edges[] = $edge;
    }
    $edges[] = (float) (ceil(($max / $step) - 1e-9) * $step);
    return $edges;
  }

  /**
   * @return float[]
   */
  private static function logEdges(float $min, float $max, int $count): array {
    $logMin = log10(max($min, 1));
    $logMax = log10($max);
    $step = ($logMax - $logMin) / $count;

    $last = self::niceFloor(max($min, 1));
    $edges = [$last];
    for ($i = 1; $i < $count; $i++) {
      $raw = 10 ** ($logMin + ($step * $i));
      $nice = self::niceCeiling($raw);
      if ($nice > $last) {
        $edges[] = $nice;
        $last = $nice;
      }
    }
    $finalHigh = self::niceCeiling($max);
    if ($finalHigh > $last) {
      $edges[] = $finalHigh;
    }
    return $edges;
  }

  /**
   * Round x up to the next 1-2-5 series number.
   */
  public static function niceCeiling(float $x): float {
    if ($x <= 0) {
      return 0.0;
    }
    $exp = (int) floor(log10($x));
    $mantissa = $x / (10 ** $exp);
    foreach (self::NICE_MANTISSAS as $m) {
      if ($mantissa <= ($m + 1e-9)) {
        return $m * (10 ** $exp);
      }
    }
    return 10 * (10 ** $exp);
  }

  /**
   * Round x down to the previous 1-2-5 series number.
   */
  public static function niceFloor(float $x): float {
    if ($x <= 0) {
      return 0.0;
    }
    $exp = (int) floor(log10($x));
    $mantissa = $x / (10 ** $exp);
    foreach (array_reverse(self::NICE_MANTISSAS) as $m) {
      if ($mantissa >= ($m - 1e-9)) {
        return $m * (10 ** $exp);
      }
    }
    return 10 ** $exp;
  }

  /**
   * Place each amount in its bucket and accumulate count/sum.
   *
   * Amounts outside the edge range (possible with caller-supplied edges;
   * adaptive edges always cover the data) land in extra "<low" / "≥high"
   * buckets instead of silently vanishing from the histogram.
   *
   * @param array<int, float> $amounts
   * @param float[] $edges
   *
   * @return array<int, array{low: float, high: float, count: int, sum: float, label: string}>
   * @mago-expect lint:halstead
   */
  private static function distribute(array $amounts, array $edges): array {
    $bucketCount = count($edges) - 1;
    /** @var array<int, array{low: float, high: float, count: int, sum: float, label: string}> $buckets */
    $buckets = [];
    for ($i = 0; $i < $bucketCount; $i++) {
      $buckets[$i] = [
        'low' => $edges[$i],
        'high' => $edges[$i + 1],
        'count' => 0,
        'sum' => 0.0,
        'label' => self::formatRange($edges[$i], $edges[$i + 1]),
      ];
    }
    $underflow = ['count' => 0, 'sum' => 0.0];
    $overflow = ['count' => 0, 'sum' => 0.0];
    foreach ($amounts as $amount) {
      if ($amount < $edges[0]) {
        $underflow['count']++;
        $underflow['sum'] += $amount;
        continue;
      }
      if ($amount > $edges[$bucketCount]) {
        $overflow['count']++;
        $overflow['sum'] += $amount;
        continue;
      }
      for ($i = 0; $i < $bucketCount; $i++) {
        $isLast = $i === ($bucketCount - 1);
        $inBucket = $amount >= $edges[$i] && ($isLast ? $amount <= $edges[$i + 1] : $amount < $edges[$i + 1]);
        if ($inBucket) {
          // Read-modify-write rather than `$buckets[$i]['count']++`: phpstan
          // cannot prove the dynamic offset exists and would widen the bucket
          // shape on a nested write.
          $bucket = $buckets[$i];
          $bucket['count']++;
          $bucket['sum'] += $amount;
          $buckets[$i] = $bucket;
          break;
        }
      }
    }
    if ($underflow['count'] > 0) {
      array_unshift($buckets, [
        'low' => 0.0,
        'high' => $edges[0],
        'count' => $underflow['count'],
        'sum' => $underflow['sum'],
        'label' => '<' . self::formatNumber($edges[0]),
      ]);
    }
    if ($overflow['count'] > 0) {
      $buckets[] = [
        'low' => $edges[$bucketCount],
        'high' => INF,
        'count' => $overflow['count'],
        'sum' => $overflow['sum'],
        'label' => '≥' . self::formatNumber($edges[$bucketCount]),
      ];
    }
    return array_values($buckets);
  }

  private static function formatRange(float $low, float $high): string {
    return self::formatNumber($low) . '–' . self::formatNumber($high);
  }

  private static function formatNumber(float $n): string {
    if ($n >= 1000) {
      $k = $n / 1000;
      return (fmod($k, 1) < 1e-9 ? (string) (int) $k : rtrim(rtrim(number_format($k, 1, '.', ''), '0'), '.')) . 'k';
    }
    // Keep sub-integer edges readable (0.5 → "0.5", not "1").
    if (abs($n - round($n)) > 1e-9) {
      return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
    return (string) (int) round($n);
  }

  private static function padLabel(string $label, int $width): string {
    $pad = $width - mb_strlen($label);
    return $label . ($pad > 0 ? str_repeat(' ', $pad) : '');
  }

}
