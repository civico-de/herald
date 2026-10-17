<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use CRM_Herald_ExtensionUtil as E;

/**
 * The Smarty modifier herald_histogram: an ASCII chart of one column of a
 * source's rows, with 8 adaptive buckets, a bucket count or fixed edges:
 *
 *   <pre>{$amounts.rows|herald_histogram:"amount"}</pre>
 *   <pre>{$amounts.rows|herald_histogram:"amount":5}</pre>
 *   <pre>{$amounts.rows|herald_histogram:"amount":"0,10,50,250"}</pre>
 */
final class HistogramModifier {

  public const NAME = 'herald_histogram';

  /**
   * Smarty refuses to register a modifier twice.
   */
  public static function register(): void {
    $smarty = \CRM_Core_Smarty::singleton();
    if ($smarty->getRegisteredPlugin('modifier', self::NAME) === NULL) {
      $smarty->registerPlugin('modifier', self::NAME, [self::class, 'apply']);
    }
  }

  /**
   * The chart holds no <, > or &, so it needs no escaping in HTML or text.
   *
   * @throws DigestProblem
   */
  public static function apply(mixed $rows, mixed $column = NULL, mixed $buckets = 8): string {
    [$rows, $column] = ChartRows::of(self::NAME, $rows, $column);
    $amounts = array_values(array_filter(ChartRows::numbers(self::NAME, $rows, $column), is_float(...)));
    [$count, $edges] = self::buckets($buckets);
    return HistogramFormatter::render(HistogramFormatter::compute($amounts, $count, $edges));
  }

  /**
   * @return array{int, list<float>|null}
   * @throws DigestProblem
   */
  private static function buckets(mixed $buckets): array {
    if (is_string($buckets) && str_contains($buckets, ',')) {
      return [8, BucketEdges::parse($buckets)];
    }
    $count = filter_var($buckets, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (is_int($count)) {
      return [$count, NULL];
    }
    throw new DigestProblem([E::ts('herald_histogram: "%1" is neither a number of buckets nor edges such as "0,10,50".', [
      1 => is_scalar($buckets) ? (string) $buckets : get_debug_type($buckets),
    ])]);
  }

}
