<?php

namespace Civi\Herald\Digest;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic unit tests — no Civi headless needed.
 * @group unit
 */
class HistogramFormatterTest extends TestCase {

  public function testEmptyInputReturnsEmpty(): void {
    $this->assertSame([], HistogramFormatter::compute([]));
    $this->assertSame([], HistogramFormatter::compute([0, -5, -1]));
  }

  public function testRenderWithEmptyBuckets(): void {
    $this->assertSame('(no data)', HistogramFormatter::render([]));
  }

  public function testNiceCeiling(): void {
    // already a 1-2-5 nice (5×10⁻¹)
    $this->assertSame(0.5, HistogramFormatter::niceCeiling(0.5));
    // next up after 0.5
    $this->assertSame(1.0, HistogramFormatter::niceCeiling(0.6));
    $this->assertSame(1.0, HistogramFormatter::niceCeiling(1.0));
    $this->assertSame(2.0, HistogramFormatter::niceCeiling(1.5));
    $this->assertSame(5.0, HistogramFormatter::niceCeiling(3.5));
    $this->assertSame(10.0, HistogramFormatter::niceCeiling(7.0));
    $this->assertSame(50.0, HistogramFormatter::niceCeiling(42.0));
    $this->assertSame(500.0, HistogramFormatter::niceCeiling(380.0));
    $this->assertSame(5000.0, HistogramFormatter::niceCeiling(2700.0));
  }

  public function testNiceFloor(): void {
    $this->assertSame(1.0, HistogramFormatter::niceFloor(1.0));
    $this->assertSame(1.0, HistogramFormatter::niceFloor(1.5));
    $this->assertSame(2.0, HistogramFormatter::niceFloor(3.0));
    $this->assertSame(5.0, HistogramFormatter::niceFloor(7.0));
    $this->assertSame(10.0, HistogramFormatter::niceFloor(15.0));
  }

  public function testSmallNgoRange(): void {
    // €1-€50, n=80 — expected linear mode (logSpan < 2)
    $amounts = $this->logUniform(80, 1, 50, 42);
    $buckets = HistogramFormatter::compute($amounts);

    $this->assertNotEmpty($buckets);
    $this->assertGreaterThanOrEqual(2, count($buckets));
    $this->assertSame(80, array_sum(array_column($buckets, 'count')));
    $this->assertEqualsWithDelta(array_sum($amounts), array_sum(array_column($buckets, 'sum')), 0.01);
    // First bucket starts at the nice floor of min
    $this->assertSame(1.0, $buckets[0]['low']);
    // Last bucket ends ≥ max
    $this->assertGreaterThanOrEqual(max($amounts), end($buckets)['high']);
  }

  public function testMidRangeUsesLogMode(): void {
    // €1-€500, n=150 — logSpan ~2.7 → log mode, finer granularity
    $amounts = $this->logUniform(150, 1, 500, 42);
    $buckets = HistogramFormatter::compute($amounts);

    $this->assertGreaterThanOrEqual(4, count($buckets));
    $this->assertSame(150, array_sum(array_column($buckets, 'count')));
    // Edges should be 1-2-5 nice numbers
    foreach ($buckets as $b) {
      $this->assertTrue($this->isNiceNumber($b['low']), "low {$b['low']} is not a 1-2-5 nice number");
      $this->assertTrue($this->isNiceNumber($b['high']), "high {$b['high']} is not a 1-2-5 nice number");
    }
  }

  public function testMajorDonorRange(): void {
    // €1-€10k, n=200 — wide range, log mode with up to 8 buckets
    $amounts = $this->logUniform(200, 1, 10000, 42);
    $buckets = HistogramFormatter::compute($amounts);

    $this->assertGreaterThanOrEqual(6, count($buckets));
    $this->assertLessThanOrEqual(8, count($buckets));
    $this->assertSame(200, array_sum(array_column($buckets, 'count')));
    $this->assertGreaterThanOrEqual(10000.0, end($buckets)['high']);
  }

  public function testTinyDatasetFallsBackToFewerBuckets(): void {
    $buckets = HistogramFormatter::compute([3, 5, 5, 7, 12, 12, 15]);
    $this->assertNotEmpty($buckets);
    $this->assertSame(7, array_sum(array_column($buckets, 'count')));
  }

  public function testUniformValuesProduceSingleBucket(): void {
    $buckets = HistogramFormatter::compute([5, 5, 5, 5, 5, 5, 5, 5]);
    $this->assertCount(1, $buckets);
    $this->assertSame(8, $buckets[0]['count']);
    $this->assertSame(40.0, $buckets[0]['sum']);
  }

  public function testOverrideEdgesIsRespected(): void {
    $buckets = HistogramFormatter::compute(
      [5, 12, 28, 75, 200],
      8,
      [0, 10, 25, 100, 1000],
    );
    $this->assertCount(4, $buckets);
    // 5 → [0,10), 12 → [10,25), 28 → [25,100), 75 → [25,100), 200 → [100,1000]
    $this->assertSame([1, 1, 2, 1], array_column($buckets, 'count'));
  }

  public function testOverrideEdgesCatchOutOfRangeAmountsInExtraBuckets(): void {
    // 0.5 below the first edge, 9000 above the last — with admin-supplied
    // edges these must land in "<low" / "≥high" buckets, not vanish.
    $buckets = HistogramFormatter::compute([0.5, 5, 15, 70, 9000], 8, [1.0, 10.0, 100.0]);

    $this->assertSame(5, array_sum(array_column($buckets, 'count')));
    $this->assertSame('<1', $buckets[0]['label']);
    $this->assertSame(1, $buckets[0]['count']);
    $last = end($buckets);
    $this->assertSame('≥100', $last['label']);
    $this->assertSame(1, $last['count']);
    $this->assertSame(9000.0, $last['sum']);
  }

  public function testSubIntegerEdgesKeepDecimalsInLabel(): void {
    $buckets = HistogramFormatter::compute([0.6, 0.8], 8, [0.5, 1.0]);
    $this->assertSame('0.5–1', $buckets[0]['label']);
  }

  public function testRenderProducesProportionalBars(): void {
    $buckets = [
      ['low' => 0, 'high' => 10, 'count' => 12, 'sum' => 38, 'label' => '0–10'],
      ['low' => 10, 'high' => 25, 'count' => 6, 'sum' => 95, 'label' => '10–25'],
      ['low' => 25, 'high' => 100, 'count' => 3, 'sum' => 175, 'label' => '25–100'],
    ];
    $rendered = HistogramFormatter::render($buckets, 12);
    $lines = explode("\n", $rendered);
    $this->assertCount(3, $lines);
    // Counts visible
    $this->assertStringContainsString('12', $lines[0]);
    $this->assertStringContainsString('6', $lines[1]);
    $this->assertStringContainsString('3', $lines[2]);
    // Bar for max-count bucket has the most blocks
    $this->assertGreaterThan(substr_count($lines[1], '█'), substr_count($lines[0], '█'));
    $this->assertGreaterThan(substr_count($lines[2], '█'), substr_count($lines[1], '█'));
  }

  public function testRenderHandlesAllZeroCounts(): void {
    $buckets = [['low' => 0, 'high' => 10, 'count' => 0, 'sum' => 0, 'label' => '0–10']];
    $this->assertSame('(no data)', HistogramFormatter::render($buckets));
  }

  /**
   * @return float[] */
  private function logUniform(int $n, float $min, float $max, int $seed): array {
    mt_srand($seed);
    $logMin = log10(max($min, 0.01));
    $logMax = log10($max);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
      $out[] = round(10 ** ($logMin + ((mt_rand() / mt_getrandmax()) * ($logMax - $logMin))), 2);
    }
    return $out;
  }

  private function isNiceNumber(float $x): bool {
    if ($x == 0.0) {
      return TRUE;
    }
    $exp = floor(log10($x));
    $mantissa = $x / (10 ** $exp);
    foreach ([1, 2, 5, 10] as $m) {
      if (abs($mantissa - $m) < 1e-9) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The bucket_edges action parameter decodes the string '[]' to an empty array
   * rather than NULL (empty('[]') is false), so an empty override reaches
   * compute(). Before the guard that produced -1 buckets and undefined-key reads
   * on $edges[0] and $edges[-1].
   */
  public function testAnEmptyEdgeOverrideFallsBackToAdaptiveEdges(): void {
    $amounts = [10.0, 20.0, 30.0, 40.0];
    $buckets = HistogramFormatter::compute($amounts, 4, []);
    $this->assertSame(HistogramFormatter::compute($amounts, 4), $buckets);
    $this->assertNotSame([], $buckets);
  }

  public function testASingleEdgeOverrideFallsBackToo(): void {
    $amounts = [10.0, 20.0, 30.0, 40.0];
    $buckets = HistogramFormatter::compute($amounts, 4, [100.0]);
    $this->assertSame(HistogramFormatter::compute($amounts, 4), $buckets);
  }

  public function testATwoEdgeOverrideIsHonoured(): void {
    $buckets = HistogramFormatter::compute([5.0, 50.0], 4, [0.0, 100.0]);
    $this->assertCount(1, $buckets);
    $this->assertSame(2, $buckets[0]['count']);
  }

}
