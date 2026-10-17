<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * The herald_histogram modifier on the rows of a source. Headless: problems are translated.
 *
 * @group headless
 */
class HistogramModifierTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  private const ROWS = [['amount' => 5], ['amount' => '12.50'], ['amount' => NULL], ['amount' => 300]];

  public function testFixedEdgesSortTheAmounts(): void {
    $chart = HistogramModifier::apply(self::ROWS, 'amount', '0,10,50');

    self::assertSame(
      HistogramFormatter::render(HistogramFormatter::compute([5.0, 12.5, 300.0], 8, [0.0, 10.0, 50.0])),
      $chart,
    );
    self::assertStringContainsString('≥50', $chart);
  }

  public function testABucketCountIsAccepted(): void {
    self::assertSame(
      HistogramFormatter::render(HistogramFormatter::compute([5.0, 12.5, 300.0], 3)),
      HistogramModifier::apply(self::ROWS, 'amount', 3),
    );
    self::assertSame(
      HistogramModifier::apply(self::ROWS, 'amount', 3),
      HistogramModifier::apply(self::ROWS, 'amount', '3'),
    );
  }

  public function testNoRowsIsNoData(): void {
    self::assertSame('(no data)', HistogramModifier::apply([], 'amount'));
  }

  /**
   * @return array<string, array{mixed, mixed, mixed, string}>
   */
  public static function misuses(): array {
    return [
      'not rows' => ['12', 'amount', 8, 'herald_histogram needs the rows of a source and a column'],
      'no column' => [self::ROWS, NULL, 8, 'herald_histogram needs the rows of a source and a column'],
      'unknown column' => [self::ROWS, 'betrag', 8, 'the rows have no column "betrag".'],
      'text value' => [[['amount' => 'Anna']], 'amount', 8, 'the column "amount" holds a value that is not a number.'],
      'zero buckets' => [self::ROWS, 'amount', 0, '"0" is neither a number of buckets nor edges'],
      'fraction' => [self::ROWS, 'amount', 2.5, '"2.5" is neither a number of buckets nor edges'],
      'edges with text' => [self::ROWS, 'amount', '0,x', 'the edges "0,x" must be ascending numbers, e.g. "0,10,50".'],
      'descending edges' => [self::ROWS, 'amount', '50,10', 'the edges "50,10" must be ascending numbers'],
      'a trailing comma' => [self::ROWS, 'amount', '10,', 'the edges "10," must be ascending numbers'],
    ];
  }

  /**
   * @dataProvider misuses
   */
  public function testAMisuseIsAProblem(mixed $rows, mixed $column, mixed $buckets, string $message): void {
    $this->expectException(DigestProblem::class);
    $this->expectExceptionMessage($message);
    HistogramModifier::apply($rows, $column, $buckets);
  }

  public function testAProblemNeverQuotesAValue(): void {
    try {
      HistogramModifier::apply([['amount' => 'Anna Example']], 'amount');
      self::fail('no problem');
    }
    catch (DigestProblem $e) {
      self::assertStringNotContainsString('Anna', $e->getMessage());
    }
  }

}
