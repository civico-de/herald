<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * Digest numbers per contribution, broken down by contribution source.
 */
final class SourceBreakdown {

  public function __construct(
    private readonly ContributionStatsService $stats,
  ) {}

  /**
   * @param array<string, mixed> $filters
   * @return array<string, mixed>
   */
  public function totals(array $filters, DateRange $period): array {
    $summary = $this->stats->getPeriodSummary($filters, $period);
    return [
      'totals' => ['amount' => $summary['total'], 'count' => $summary['count']],
      'by_source' => $summary['bySource'],
    ];
  }

  /**
   * @param array<string, mixed> $filters
   * @return array<string, mixed>
   */
  public function pending(array $filters): array {
    $pending = $this->stats->getAllPending($filters);
    return ['pending' => ['amount' => $pending['total'], 'count' => $pending['count']]];
  }

  /**
   * @param array<string, mixed> $filters
   * @return array{total: float, count: int}
   */
  public function campaignTotal(array $filters): array {
    return $this->stats->getCampaignTotal($filters);
  }

}
