<?php

declare(strict_types = 1);

namespace Civi\Herald\Alert;

use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Digest\SourceNormalizer;
use Civi\Herald\Util\Cast;

/**
 * Variable bag for the single-contribution alert template.
 *
 * Parameters read from the caller's params:
 *   campaign_id    — optional, computes campaign progress when provided
 *   goal           — optional, campaign goal for percent display
 *   campaign_title — string, free-text title for the campaign block
 * @mago-expect lint:cyclomatic-complexity
 */
final class ContributionAlertVars {

  /**
   * @param array<string, mixed> $params
   * @param array<array-key, mixed> $contribution Trigger entity data
   * @param int|null $previousStatusId Pre-edit status (NULL if unknown / not requested)
   * @param ContributionStatsService $stats Stats source (mockable)
   * @param callable(mixed): string|null $statusNameResolver Maps status_id → name (2 → "Pending")
   * @param SourceNormalizer|null $sourceNormalizer Optional test seam
   * @return array<string, mixed>
   * @mago-expect lint:excessive-parameter-list
   */
  public static function build(
    array $params,
    array $contribution,
    ?int $previousStatusId,
    ContributionStatsService $stats,
    ?callable $statusNameResolver = NULL,
    ?SourceNormalizer $sourceNormalizer = NULL,
  ): array {
    $statusNameResolver ??= self::resolveStatusName(...);
    $sourceNormalizer ??= new SourceNormalizer();
    if ($sourceNormalizer->isEnabled() && isset($contribution['source']) && is_string($contribution['source'])) {
      $contribution['source'] = $sourceNormalizer->normalize($contribution['source']);
    }
    $vars = [
      'contribution' => $contribution,
      'campaign' => ['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0],
      'from_status' => '',
      'to_status' => Cast::toString($statusNameResolver($contribution['contribution_status_id'] ?? NULL)),
    ];

    if ($previousStatusId !== NULL) {
      $vars['from_status'] = Cast::toString($statusNameResolver($previousStatusId));
    }

    if (!empty($params['campaign_id']) && !empty($params['goal'])) {
      $campaignTotal = $stats->getCampaignTotal(Cast::toInt($params['campaign_id']));
      $goal = Cast::toFloat($params['goal']);
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

  /**
   * Map a contribution_status_id to its machine name (Pending, Completed, …).
   */
  public static function resolveStatusName(mixed $statusId): string {
    if ($statusId === NULL || $statusId === '') {
      return '';
    }
    if (!is_numeric($statusId)) {
      return Cast::toString($statusId);
    }
    /** @var array<int, string>|null $cache */
    static $cache = NULL;
    if ($cache === NULL) {
      $rows = \Civi\Api4\OptionValue::get(checkPermissions: FALSE)
        ->addSelect('value', 'name')
        ->addWhere('option_group_id:name', '=', 'contribution_status')
        ->execute()
        ->getArrayCopy();
      $cache = [];
      foreach ($rows as $row) {
        if (!is_array($row)) {
          continue;
        }
        $cache[Cast::toInt($row['value'] ?? NULL)] = Cast::toString($row['name'] ?? NULL);
      }
    }
    return $cache[(int) $statusId] ?? '';
  }

}
