<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Trigger\CampaignProgress;
use Civi\Herald\Util\Cast;

/**
 * Variable bag for the contribution-digest templates.
 *
 * Parameters read from the caller's params:
 *   period             — "yesterday" | "today" | "last_7_days" | "last_30_days" (default "yesterday")
 *   campaign_id        — optional, narrows all queries
 *   financial_type_id  — optional
 *   source_pattern     — LIKE pattern applied to all queries (e.g. "%Bank TX%")
 *   group_by           — "line_item" groups by (line-item label, unit price)
 *                        instead of contribution source; totals/pending are
 *                        then derived from the items (mixed carts split)
 *   show_pending       — bool, include pending-pool block (default true)
 *   show_histogram     — bool, include histogram (default true)
 *   bucket_count       — int, target histogram buckets (default 8)
 *   bucket_edges       — JSON array of edges to override adaptive bucketing
 * The campaign block (title, goal_revenue, running total) comes from the
 * Campaign record named by campaign_id; without one it stays empty.
 * @mago-expect lint:cyclomatic-complexity
 */
final class DigestVars {

  /**
   * @param array<string, mixed> $params
   * @param ContributionStatsService $stats Stats source (mockable in tests)
   * @param (callable(int): ?array{title: string, goal: float})|null $campaignResolver Campaign id → title and goal
   * @return array<string, mixed>
   * @mago-expect lint:halstead
   */
  public static function build(
    array $params,
    ContributionStatsService $stats,
    ?callable $campaignResolver = NULL,
  ): array {
    $filters = self::buildFiltersFromParams($params);
    $period = self::resolvePeriod(Cast::toString($params['period'] ?? 'yesterday'));
    $groupBy = Cast::toString($params['group_by'] ?? '');

    $vars = [
      'period' => ['label' => $period->label, 'start' => $period->startSql(), 'end' => $period->endSql()],
      'totals' => ['amount' => 0, 'count' => 0],
      'by_source' => [],
      'by_item' => [],
      'pending' => ['amount' => 0, 'count' => 0],
      'pending_items' => [],
      'histogram' => '',
    ];

    if ($groupBy === 'line_item') {
      // Line-item view: totals are derived from the items themselves so the
      // headline always matches the breakdown (mixed carts split per item).
      $items = $stats->getItemSummary($filters, $period);
      $vars['by_item'] = $items;
      $vars['totals'] = [
        'amount' => array_sum(array_column($items, 'total')),
        'count' => (int) array_sum(array_column($items, 'qty')),
      ];
    }
    // @mago-expect lint:no-else-clause
    else {
      $summary = $stats->getPeriodSummary($filters, $period);
      $vars['totals'] = ['amount' => $summary['total'], 'count' => $summary['count']];
      $vars['by_source'] = $summary['bySource'];
    }

    if ((bool) ($params['show_pending'] ?? TRUE)) {
      if ($groupBy === 'line_item') {
        $pendingItems = $stats->getItemSummary($filters, NULL, 'Pending');
        $vars['pending_items'] = $pendingItems;
        $vars['pending'] = [
          'amount' => array_sum(array_column($pendingItems, 'total')),
          'count' => (int) array_sum(array_column($pendingItems, 'qty')),
        ];
      }
      // @mago-expect lint:no-else-clause
      else {
        $pending = $stats->getAllPending($filters);
        $vars['pending'] = ['amount' => $pending['total'], 'count' => $pending['count']];
      }
    }

    if ((bool) ($params['show_histogram'] ?? TRUE)) {
      $amounts = $stats->getAmountDistribution($filters, $period);
      $bucketCount = Cast::toInt($params['bucket_count'] ?? 8, 8);
      $edges = self::decodeEdges($params['bucket_edges'] ?? NULL);
      $buckets = HistogramFormatter::compute($amounts, $bucketCount, $edges);
      $vars['histogram'] = HistogramFormatter::render($buckets);
    }

    $campaignId = Cast::toInt($filters['campaign_id'] ?? 0);
    $vars['campaign'] = CampaignProgress::of(
      $campaignId,
      static fn (): array => self::campaignTotal($stats, $filters, $groupBy),
      $campaignResolver ?? CampaignProgress::resolveCampaign(...),
    );

    return $vars;
  }

  /**
   * Campaign progress at any date, counted on the same level as the totals:
   * line items in the line-item view, so the financial type filter means the same.
   *
   * @param array<string, mixed> $filters
   * @return array{total: float, count: int}
   */
  private static function campaignTotal(ContributionStatsService $stats, array $filters, string $groupBy): array {
    if ($groupBy !== 'line_item') {
      return $stats->getCampaignTotal($filters);
    }
    $items = $stats->getItemSummary($filters, NULL, 'Completed');
    return [
      'total' => (float) array_sum(array_column($items, 'total')),
      'count' => (int) array_sum(array_column($items, 'qty')),
    ];
  }

  /**
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  private static function buildFiltersFromParams(array $params): array {
    // The 0-check also drops '' from an empty form field, which would survive as (int) 0.
    return array_filter(
      [
        'campaign_id' => isset($params['campaign_id']) ? Cast::toInt($params['campaign_id']) : NULL,
        'financial_type_id' => isset($params['financial_type_id']) ? Cast::toInt($params['financial_type_id']) : NULL,
        'source_pattern' => $params['source_pattern'] ?? NULL,
        'status' => $params['status'] ?? NULL,
      ],
      static fn ($v) => $v !== NULL && $v !== '' && $v !== 0,
    );
  }

  /**
   * @param mixed $raw Either an array of edges or a JSON-encoded array.
   * @return list<float>|null
   */
  private static function decodeEdges(mixed $raw): ?array {
    if (empty($raw)) {
      return NULL;
    }
    $decoded = is_array($raw) ? $raw : json_decode(Cast::toString($raw), TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    return array_values(array_map(Cast::toFloat(...), $decoded));
  }

  public static function resolvePeriod(string $key): DateRange {
    return match ($key) {
      'today' => DateRange::today(),
      'last_7_days' => DateRange::lastDays(7),
      'last_30_days' => DateRange::lastDays(30),
      default => DateRange::yesterday(),
    };
  }

}
