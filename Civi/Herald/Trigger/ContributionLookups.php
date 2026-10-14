<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

/**
 * Where the contribution variables read campaign totals, status names,
 * campaigns and source labels from; tests pass their own.
 */
final class ContributionLookups {

  /**
   * @var \Closure(int): array{total: float, count: int} Campaign id to its completed total and count.
   */
  public readonly \Closure $campaignTotal;

  /**
   * @var \Closure(mixed): string Status id to name, 2 → "Pending".
   */
  public readonly \Closure $statusName;

  /**
   * @var \Closure(int): ?array{title: string, goal: float} Campaign id to title and goal.
   */
  public readonly \Closure $campaign;

  /**
   * @param (\Closure(int): array{total: float, count: int})|null $campaignTotal
   * @param (\Closure(mixed): string)|null $statusName
   * @param (\Closure(int): ?array{title: string, goal: float})|null $campaign
   */
  public function __construct(
    ?\Closure $campaignTotal = NULL,
    ?\Closure $statusName = NULL,
    ?\Closure $campaign = NULL,
    public readonly SourceNormalizer $sources = new SourceNormalizer(),
  ) {
    $this->campaignTotal = $campaignTotal ?? CampaignProgress::total(...);
    $this->statusName = $statusName ?? ContributionStatusName::of(...);
    $this->campaign = $campaign ?? CampaignProgress::resolveCampaign(...);
  }

}
