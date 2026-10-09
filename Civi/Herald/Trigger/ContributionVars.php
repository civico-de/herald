<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Digest\SourceNormalizer;
use Civi\Herald\StatusTransitionDispatcher;
use Civi\Herald\Util\Cast;

/**
 * Template variables for a trigger that carries a Contribution: the row,
 * its status transition and the progress of its own campaign.
 */
final class ContributionVars {

  /**
   * Variables for the trigger's contribution; [] when it carries none.
   *
   * @param array<array-key, mixed> $contribution Trigger entity data, completed from the stored row
   * @param mixed $originalStatusId contribution_status_id from the trigger's original data
   * @return array<string, mixed>
   */
  public static function forTrigger(array $contribution, mixed $originalStatusId): array {
    $id = Cast::toInt($contribution['id'] ?? 0);
    if ($id <= 0) {
      return [];
    }
    $previousStatusId = StatusTransitionDispatcher::resolvePreEditStatus(
      $id,
      is_numeric($originalStatusId) ? (int) $originalStatusId : NULL,
    );
    return self::build($contribution, $previousStatusId, new ContributionStatsService());
  }

  /**
   * @param array<array-key, mixed> $contribution Trigger entity data, completed from the stored row
   * @param int|null $previousStatusId Pre-edit status, NULL when unknown
   * @param callable(mixed): string|null $statusNameResolver Maps status_id → name (2 → "Pending")
   * @param callable(int): ?array{title: string, goal: float}|null $campaignResolver Campaign id → title and goal
   * @return array<string, mixed>
   * @mago-expect lint:excessive-parameter-list
   */
  public static function build(
    array $contribution,
    ?int $previousStatusId,
    ContributionStatsService $stats,
    ?callable $statusNameResolver = NULL,
    ?callable $campaignResolver = NULL,
    ?SourceNormalizer $sourceNormalizer = NULL,
  ): array {
    $statusNameResolver ??= ContributionStatusName::of(...);
    $campaignResolver ??= CampaignProgress::resolveCampaign(...);
    $sourceNormalizer ??= new SourceNormalizer();
    if ($sourceNormalizer->isEnabled() && isset($contribution['source']) && is_string($contribution['source'])) {
      $contribution['source'] = $sourceNormalizer->normalize($contribution['source']);
    }
    return [
      'contribution' => $contribution,
      'campaign' => self::campaign(Cast::toInt($contribution['campaign_id'] ?? 0), $stats, $campaignResolver),
      'from_status' => $previousStatusId !== NULL ? Cast::toString($statusNameResolver($previousStatusId)) : '',
      'to_status' => Cast::toString($statusNameResolver($contribution['contribution_status_id'] ?? NULL)),
    ];
  }

  /**
   * @param callable(int): ?array{title: string, goal: float} $campaignResolver
   * @return array<string, mixed>
   */
  private static function campaign(
    int $campaignId,
    ContributionStatsService $stats,
    callable $campaignResolver,
  ): array {
    try {
      return CampaignProgress::of(
        $campaignId,
        static fn (): array => $stats->getCampaignTotal(['campaign_id' => $campaignId]),
        $campaignResolver,
      );
    }
    catch (\Throwable $e) {
      // CiviCampaign disabled or a read failed: the alert goes out without progress.
      \Civi::log()->error('herald: could not resolve contribution campaign', [
        'campaign_id' => $campaignId,
        'error' => $e->getMessage(),
      ]);
      return CampaignProgress::EMPTY;
    }
  }

}
