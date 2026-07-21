<?php

use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\StatusTransitionDispatcher;

/**
 * CiviRules action: send a single-contribution alert (created, updated, status_changed).
 *
 * Pulls the Contribution from the trigger data, optionally enriches with
 * campaign-progress stats, and renders the chosen template.
 *
 * Action parameters (in addition to those from AbstractSend):
 *   campaign_id       — optional, computes campaign progress when provided
 *   goal              — optional, campaign goal for percent display
 *   campaign_title    — string, free-text title for the campaign block
 *   include_status_transition — bool (default true): pass from_status / to_status
 *                       to the template by reading the pre-edit snapshot
 */
class CRM_Herald_CivirulesActions_SendContributionAlert extends CRM_Herald_CivirulesActions_AbstractSend {

  protected function getFormPath(): string {
    return 'civicrm/herald/form/civirulesaction/sendcontributionalert';
  }

  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
    $params = $this->getActionParameters();
    $contribution = $triggerData->getEntityData('Contribution');
    if (empty($contribution) || !is_array($contribution)) {
      throw new \CRM_Core_Exception('herald: SendContributionAlert requires a Contribution in trigger data.');
    }

    $previousStatusId = NULL;
    if (!empty($contribution['id']) && (bool) ($params['include_status_transition'] ?? TRUE)) {
      $previousStatusId = StatusTransitionDispatcher::resolvePreEditStatus(self::asInt($contribution['id']), $triggerData);
    }

    $vars = self::buildVars($params, $contribution, $previousStatusId, $this->makeStatsService(), [$this, 'resolveStatusName']);
    $this->dispatch($vars);
  }

  /**
   * Pure assembly of the alert variable bag. Test-seam.
   *
   * @param array<string, mixed> $params CiviRules action parameters
   * @param array<array-key, mixed> $contribution Trigger entity data
   * @param int|null $previousStatusId Pre-edit status (NULL if unknown / not requested)
   * @param ContributionStatsService $stats Stats source (mockable)
   * @param callable(mixed): string $statusNameResolver Maps status_id → name (e.g. 2 → "Pending")
   * @return array<string, mixed>
   */
  public static function buildVars(
    array $params,
    array $contribution,
    ?int $previousStatusId,
    ContributionStatsService $stats,
    callable $statusNameResolver
  ): array {
    $vars = [
      'contribution' => $contribution,
      'campaign' => ['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0],
      'from_status' => '',
      'to_status' => self::asString($statusNameResolver($contribution['contribution_status_id'] ?? NULL)),
    ];

    if ($previousStatusId !== NULL) {
      $vars['from_status'] = self::asString($statusNameResolver($previousStatusId));
    }

    if (!empty($params['campaign_id']) && !empty($params['goal'])) {
      $campaignTotal = $stats->getCampaignTotal(self::asInt($params['campaign_id']));
      $goal = self::asFloat($params['goal']);
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

  /**
   * Map a contribution_status_id to its machine name (Pending, Completed, …).
   * Public so buildVars() can pass it as the resolver callable in tests.
   */
  public function resolveStatusName(mixed $statusId): string {
    if ($statusId === NULL || $statusId === '') {
      return '';
    }
    if (!is_numeric($statusId)) {
      return self::asString($statusId);
    }
    /** @var array<int, string>|null $cache */
    static $cache = NULL;
    if ($cache === NULL) {
      $rows = \Civi\Api4\OptionValue::get(FALSE)
        ->addSelect('value', 'name')
        ->addWhere('option_group_id:name', '=', 'contribution_status')
        ->execute()
        ->getArrayCopy();
      $cache = [];
      foreach ($rows as $row) {
        if (!is_array($row)) {
          continue;
        }
        $cache[self::asInt($row['value'] ?? NULL)] = self::asString($row['name'] ?? NULL);
      }
    }
    return $cache[(int) $statusId] ?? '';
  }

}
