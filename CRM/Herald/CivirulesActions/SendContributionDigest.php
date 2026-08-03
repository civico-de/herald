<?php

declare(strict_types = 1);

use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Digest\DateRange;
use Civi\Herald\Digest\HistogramFormatter;

/**
 * CiviRules action: send a contribution digest (daily/weekly/custom).
 *
 * Action parameters (in addition to those from AbstractSend):
 *   period             — "yesterday" | "today" | "last_7_days" | "last_30_days" (default "yesterday")
 *   campaign_id        — optional, narrows all queries
 *   financial_type_id  — optional
 *   source_pattern     — LIKE pattern applied to all queries (e.g. "%BB-TX-%")
 *   group_by           — "line_item" groups by (line-item label, unit price)
 *                        instead of contribution source; totals/pending are
 *                        then derived from the items (mixed carts split)
 *   show_pending       — bool, include pending-pool block (default true)
 *   show_histogram     — bool, include histogram (default true)
 *   bucket_count       — int, target histogram buckets (default 8)
 *   bucket_edges       — JSON array of edges to override adaptive bucketing
 *   goal               — float, campaign goal (renders progress when set)
 *   campaign_title     — string, free-text title for the campaign block
 * @mago-expect lint:cyclomatic-complexity
 */
class CRM_Herald_CivirulesActions_SendContributionDigest extends CRM_Herald_CivirulesActions_AbstractSend {

  protected function getFormPath(): string {
    return 'civicrm/herald/form/civirulesaction/sendcontributiondigest';
  }

  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
    $params = $this->getActionParameters();
    $vars = self::buildVars($params, $this->makeStatsService());
    $this->dispatch($vars);
  }

  /**
   * Pure assembly of the template variable bag from action params + stats.
   * Extracted so unit tests can verify the wiring without DB access.
   *
   * @param array<string, mixed> $params CiviRules action parameters
   * @param ContributionStatsService $stats Stats source (mockable in tests)
   * @return array<string, mixed> variable bag for the template
   * @mago-expect lint:halstead
   */
  public static function buildVars(array $params, ContributionStatsService $stats): array {
    $filters = self::buildFiltersFromParams($params);
    $period = self::resolvePeriod(self::asString($params['period'] ?? 'yesterday'));
    $groupBy = self::asString($params['group_by'] ?? '');

    $vars = [
      'period' => ['label' => $period->label, 'start' => $period->startSql(), 'end' => $period->endSql()],
      'totals' => ['amount' => 0, 'count' => 0],
      'by_source' => [],
      'by_item' => [],
      'pending' => ['amount' => 0, 'count' => 0],
      'pending_items' => [],
      'campaign' => ['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0],
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
      $amounts = $stats->getAmountDistribution($filters);
      $bucketCount = self::asInt($params['bucket_count'] ?? 8, 8);
      $edges = self::decodeEdges($params['bucket_edges'] ?? NULL);
      $buckets = HistogramFormatter::compute($amounts, $bucketCount, $edges);
      $vars['histogram'] = HistogramFormatter::render($buckets);
    }

    if (!empty($params['goal'])) {
      $goal = self::asFloat($params['goal']);
      $campaignTotal = !empty($filters['campaign_id'])
        ? $stats->getCampaignTotal(self::asInt($filters['campaign_id']))
        // Without a campaign filter the digest's own totals ARE the campaign
        // total. Read them back from $vars, which both branches above fill —
        // $summary only exists in the non-line_item one.
        : ['total' => $vars['totals']['amount'], 'count' => $vars['totals']['count']];
      $vars['campaign'] = [
        'title' => $params['campaign_title'] ?? '',
        'goal' => $goal,
        'total' => $campaignTotal['total'],
        'count' => $campaignTotal['count'],
        'percent' => $goal > 0 ? round(($campaignTotal['total'] / $goal) * 100, 1) : 0,
      ];
    }

    return $vars;
  }

  protected function makeStatsService(): ContributionStatsService {
    return new ContributionStatsService();
  }

  /**
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  private static function buildFiltersFromParams(array $params): array {
    // The 0-check also drops legacy params where an empty form field was
    // stored as '' and would otherwise survive as (int) 0.
    return array_filter(
      [
        'campaign_id' => isset($params['campaign_id']) ? self::asInt($params['campaign_id']) : NULL,
        'financial_type_id' => isset($params['financial_type_id']) ? self::asInt($params['financial_type_id']) : NULL,
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
    $decoded = is_array($raw) ? $raw : json_decode(self::asString($raw), TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    return array_values(array_map(self::asFloat(...), $decoded));
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
