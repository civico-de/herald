<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Digest\SourceNormalizer;

/**
 * Where the contribution variables read campaign totals, status names,
 * campaigns and source labels from; tests pass their own.
 */
final class ContributionLookups {

  /**
   * @var \Closure(mixed): string Status id to name, 2 → "Pending".
   */
  public readonly \Closure $statusName;

  /**
   * @var \Closure(int): ?array{title: string, goal: float} Campaign id to title and goal.
   */
  public readonly \Closure $campaign;

  /**
   * @param (\Closure(mixed): string)|null $statusName
   * @param (\Closure(int): ?array{title: string, goal: float})|null $campaign
   */
  public function __construct(
    public readonly ContributionStatsService $stats = new ContributionStatsService(),
    ?\Closure $statusName = NULL,
    ?\Closure $campaign = NULL,
    public readonly SourceNormalizer $sources = new SourceNormalizer(),
  ) {
    $this->statusName = $statusName ?? ContributionStatusName::of(...);
    $this->campaign = $campaign ?? CampaignProgress::resolveCampaign(...);
  }

}
