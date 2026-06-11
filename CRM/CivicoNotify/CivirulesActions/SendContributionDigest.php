<?php

use Civi\CivicoNotify\Digest\ContributionStatsService;
use Civi\CivicoNotify\Digest\DateRange;
use Civi\CivicoNotify\Digest\HistogramFormatter;

/**
 * CiviRules action: send a contribution digest (daily/weekly/custom).
 *
 * Action parameters (in addition to those from AbstractSend):
 *   period             — "yesterday" | "today" | "last_7_days" | "last_30_days" (default "yesterday")
 *   campaign_id        — optional, narrows all queries
 *   financial_type_id  — optional
 *   source_pattern     — LIKE pattern applied to all queries (e.g. "%BB-TX-%")
 *   show_pending       — bool, include pending-pool block (default true)
 *   show_histogram     — bool, include histogram (default true)
 *   bucket_count       — int, target histogram buckets (default 8)
 *   bucket_edges       — JSON array of edges to override adaptive bucketing
 *   goal               — float, campaign goal (renders progress when set)
 *   campaign_title     — string, free-text title for the campaign block
 */
class CRM_CivicoNotify_CivirulesActions_SendContributionDigest extends CRM_CivicoNotify_CivirulesActions_AbstractSend {

  protected function getFormPath(): string {
    return 'civicrm/civicoNotify/form/civirulesaction/sendcontributiondigest';
  }

  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $params = $this->getActionParameters();
    $vars = self::buildVars($params, $this->makeStatsService());
    $this->dispatch($vars);
  }

  /**
   * Pure assembly of the template variable bag from action params + stats.
   * Extracted so unit tests can verify the wiring without DB access.
   *
   * @param array $params CiviRules action parameters
   * @param ContributionStatsService $stats Stats source (mockable in tests)
   * @return array variable bag for the template
   */
  public static function buildVars(array $params, ContributionStatsService $stats): array {
    $filters = self::buildFiltersFromParams($params);
    $period = self::resolvePeriod((string) ($params['period'] ?? 'yesterday'));
    $summary = $stats->getPeriodSummary($filters, $period);

    $vars = [
      'period' => ['label' => $period->label, 'start' => $period->startSql(), 'end' => $period->endSql()],
      'totals' => ['amount' => $summary['total'], 'count' => $summary['count']],
      'by_source' => $summary['bySource'],
      'pending' => ['amount' => 0, 'count' => 0],
      'campaign' => ['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0],
      'histogram' => '',
    ];

    if ((bool) ($params['show_pending'] ?? TRUE)) {
      $pending = $stats->getAllPending($filters);
      $vars['pending'] = ['amount' => $pending['total'], 'count' => $pending['count']];
    }

    if ((bool) ($params['show_histogram'] ?? TRUE)) {
      $amounts = $stats->getAmountDistribution($filters);
      $bucketCount = (int) ($params['bucket_count'] ?? 8);
      $edges = self::decodeEdges($params['bucket_edges'] ?? NULL);
      $buckets = HistogramFormatter::compute($amounts, $bucketCount, $edges);
      $vars['histogram'] = HistogramFormatter::render($buckets);
    }

    if (!empty($params['goal'])) {
      $goal = (float) $params['goal'];
      $campaignTotal = !empty($filters['campaign_id'])
        ? $stats->getCampaignTotal((int) $filters['campaign_id'])
        : ['total' => $summary['total'], 'count' => $summary['count']];
      $vars['campaign'] = [
        'title' => $params['campaign_title'] ?? '',
        'goal' => $goal,
        'total' => $campaignTotal['total'],
        'count' => $campaignTotal['count'],
        'percent' => $goal > 0 ? round($campaignTotal['total'] / $goal * 100, 1) : 0,
      ];
    }

    return $vars;
  }

  protected function makeStatsService(): ContributionStatsService {
    return new ContributionStatsService();
  }

  private static function buildFiltersFromParams(array $params): array {
    // The 0-check also drops legacy params where an empty form field was
    // stored as '' and would otherwise survive as (int) 0.
    return array_filter([
      'campaign_id' => isset($params['campaign_id']) ? (int) $params['campaign_id'] : NULL,
      'financial_type_id' => isset($params['financial_type_id']) ? (int) $params['financial_type_id'] : NULL,
      'source_pattern' => $params['source_pattern'] ?? NULL,
      'status' => $params['status'] ?? NULL,
    ], static fn($v) => $v !== NULL && $v !== '' && $v !== 0);
  }

  /**
   * @return float[]|null
   */
  private static function decodeEdges($raw): ?array {
    if (empty($raw)) {
      return NULL;
    }
    $decoded = is_array($raw) ? $raw : json_decode((string) $raw, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    return array_values(array_map('floatval', $decoded));
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
