<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Herald\Util\Cast;

/**
 * Progress of a contribution's campaign: title and goal from the Campaign
 * record (goal_revenue), total and count of its completed contributions.
 *
 * The block is always complete (zeros without a campaign) so templates can
 * test `{if $campaign.goal}`.
 */
final class CampaignProgress {

  public const EMPTY = ['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0];

  /**
   * @param callable(): array{total: float, count: int} $total Running total of the campaign
   * @param callable(int): ?array{title: string, goal: float} $campaignResolver
   * @return array<string, mixed>
   */
  public static function of(int $campaignId, callable $total, callable $campaignResolver): array {
    if ($campaignId <= 0) {
      return self::EMPTY;
    }
    $campaign = $campaignResolver($campaignId);
    if ($campaign === NULL) {
      return self::EMPTY;
    }
    $total = $total();
    $goal = $campaign['goal'];
    return [
      'title' => $campaign['title'],
      'goal' => $goal,
      'total' => $total['total'],
      'count' => $total['count'],
      'percent' => $goal > 0 ? round(($total['total'] / $goal) * 100, 1) : 0,
    ];
  }

  /**
   * @return array{title: string, goal: float}|null NULL when the campaign is gone.
   */
  public static function resolveCampaign(int $campaignId): ?array {
    $row = \Civi\Api4\Campaign::get(checkPermissions: FALSE)
      ->addSelect('title', 'goal_revenue')
      ->addWhere('id', '=', $campaignId)
      ->execute()
      ->first();
    if (!is_array($row)) {
      return NULL;
    }
    return ['title' => Cast::toString($row['title'] ?? ''), 'goal' => Cast::toFloat($row['goal_revenue'] ?? 0)];
  }

}
