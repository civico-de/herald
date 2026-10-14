<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * Digest numbers per line item and unit price. Totals come from the items, so
 * the headline matches the breakdown and a mixed cart is split across its items.
 */
final class LineItemBreakdown {

  public function __construct(
    private readonly ContributionStatsService $stats,
  ) {}

  /**
   * @param array<string, mixed> $filters
   * @return array<string, mixed>
   */
  public function totals(array $filters, DateRange $period): array {
    $items = $this->stats->getItemSummary($filters, $period);
    return ['totals' => self::sum($items), 'by_item' => $items];
  }

  /**
   * @param array<string, mixed> $filters
   * @return array<string, mixed>
   */
  public function pending(array $filters): array {
    $items = $this->stats->getItemSummary($filters, NULL, 'Pending');
    return ['pending' => self::sum($items), 'pending_items' => $items];
  }

  /**
   * Campaign progress at any date, counted on items so the financial type filter means the same.
   *
   * @param array<string, mixed> $filters
   * @return array{total: float, count: int}
   */
  public function campaignTotal(array $filters): array {
    $sum = self::sum($this->stats->getItemSummary($filters, NULL, 'Completed'));
    return ['total' => $sum['amount'], 'count' => $sum['count']];
  }

  /**
   * @param array<int, array{label: string, unit_price: float, qty: float|int, total: float}> $items
   * @return array{amount: float, count: int}
   */
  private static function sum(array $items): array {
    return [
      'amount' => (float) array_sum(array_column($items, 'total')),
      'count' => (int) array_sum(array_column($items, 'qty')),
    ];
  }

}
