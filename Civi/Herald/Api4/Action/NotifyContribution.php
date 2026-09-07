<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Alert\ContributionAlertVars;
use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Dispatch\Dispatcher;
use Civi\Herald\StatusTransitionDispatcher;
use Civi\Herald\Util\Cast;

/**
 * Render and deliver a single-contribution alert with campaign progress and
 * status transition.
 */
class NotifyContribution extends AbstractAction {

  /**
   * Parameter bag — the Dispatcher keys plus campaign_id, goal, campaign_title
   * and include_status_transition.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $actionParams = [];

  /**
   * The contribution record as the caller's trigger delivered it.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $contribution = [];

  /**
   * contribution_status_id from the caller's own pre-edit record; 0 = unknown,
   * in which case herald's own hook_civicrm_pre snapshot is used.
   * @var int
   */
  protected int $originalStatusId = 0;

  public function _run(Result $result): void {
    if ($this->contribution === []) {
      throw new \CRM_Core_Exception('Herald.notifyContribution: "contribution" parameter is required.');
    }

    $previousStatusId = NULL;
    if (
      !empty($this->contribution['id'])
      && (bool) ($this->actionParams['include_status_transition'] ?? TRUE)
    ) {
      $previousStatusId = StatusTransitionDispatcher::resolvePreEditStatus(
        Cast::toInt($this->contribution['id']),
        $this->originalStatusId ?: NULL,
      );
    }

    $vars = ContributionAlertVars::build(
      $this->actionParams,
      $this->contribution,
      $previousStatusId,
      new ContributionStatsService(),
    );
    $result[] = (new Dispatcher())->dispatch($this->actionParams, $vars);
  }

}
