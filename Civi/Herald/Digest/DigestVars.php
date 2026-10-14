<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Trigger\CampaignProgress;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

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
 */
final class DigestVars {

  /**
   * @param array<string, mixed> $params
   * @param ContributionStatsService $stats Stats source (mockable in tests)
   * @param (callable(int): ?array{title: string, goal: float})|null $campaignResolver Campaign id → title and goal
   * @return array<string, mixed>
   */
  public static function build(
    array $params,
    ContributionStatsService $stats,
    ?callable $campaignResolver = NULL,
  ): array {
    // The stats service skips empty filters.
    $filters = array_intersect_key($params, array_flip(['campaign_id', 'financial_type_id', 'source_pattern']));
    $period = DateRange::ofPeriod(Cast::toString($params['period'] ?? 'yesterday'));
    $breakdown = Cast::toString($params['group_by'] ?? '') === 'line_item'
      ? new LineItemBreakdown($stats)
      : new SourceBreakdown($stats);
    $vars = [
      'period' => ['label' => $period->label, 'start' => $period->startSql(), 'end' => $period->endSql()],
      'by_source' => [],
      'by_item' => [],
      'pending' => ['amount' => 0, 'count' => 0],
      'pending_items' => [],
      'histogram' => '',
    ];
    $vars = $breakdown->totals($filters, $period) + $vars;
    if (!empty($params['show_pending'] ?? TRUE)) {
      $vars = $breakdown->pending($filters) + $vars;
    }
    if (!empty($params['show_histogram'] ?? TRUE)) {
      $buckets = HistogramFormatter::compute(
        $stats->getAmountDistribution($filters, $period),
        Cast::toInt($params['bucket_count'] ?? 8, 8),
        BucketEdges::parse($params['bucket_edges'] ?? NULL),
      );
      $vars['histogram'] = HistogramFormatter::render($buckets);
    }
    $vars['campaign'] = CampaignProgress::of(
      Cast::toInt($filters['campaign_id'] ?? 0),
      static fn (): array => $breakdown->campaignTotal($filters),
      $campaignResolver ?? CampaignProgress::resolveCampaign(...),
    );
    return $vars;
  }

}
