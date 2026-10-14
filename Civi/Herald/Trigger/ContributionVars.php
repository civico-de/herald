<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Herald\StatusTransitionDispatcher;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

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
    return self::build($contribution, $previousStatusId);
  }

  /**
   * @param array<array-key, mixed> $contribution Trigger entity data, completed from the stored row
   * @param int|null $previousStatusId Pre-edit status, NULL when unknown
   * @return array<string, mixed>
   */
  public static function build(
    array $contribution,
    ?int $previousStatusId,
    ContributionLookups $lookups = new ContributionLookups(),
  ): array {
    if (is_string($contribution['source'] ?? NULL)) {
      $contribution['source'] = $lookups->sources->normalize($contribution['source']);
    }
    $statusName = $lookups->statusName;
    return [
      'contribution' => $contribution,
      'campaign' => self::campaign(Cast::toInt($contribution['campaign_id'] ?? 0), $lookups),
      'from_status' => $previousStatusId !== NULL ? Cast::toString($statusName($previousStatusId)) : '',
      'to_status' => Cast::toString($statusName($contribution['contribution_status_id'] ?? NULL)),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private static function campaign(int $campaignId, ContributionLookups $lookups): array {
    try {
      return CampaignProgress::of(
        $campaignId,
        static fn (): array => ($lookups->campaignTotal)($campaignId),
        $lookups->campaign,
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
