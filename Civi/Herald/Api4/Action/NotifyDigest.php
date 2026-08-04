<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Digest\DigestVars;
use Civi\Herald\Dispatch\Dispatcher;

/**
 * Render and deliver a contribution digest.
 *
 * Needs no trigger entity — the period and filters come entirely from the
 * parameter bag. Job.herald_contribution_digest is the scheduled counterpart
 * and shares the same variable builder.
 */
class NotifyDigest extends AbstractAction {

  /**
   * Parameter bag — the Dispatcher keys plus the digest filters
   * (period, campaign_id, group_by, show_pending, …). See Civi\Herald\Digest\DigestVars.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $actionParams = [];

  public function _run(Result $result): void {
    $vars = DigestVars::build($this->actionParams, new ContributionStatsService());
    $result[] = (new Dispatcher())->dispatch($this->actionParams, $vars);
  }

}
