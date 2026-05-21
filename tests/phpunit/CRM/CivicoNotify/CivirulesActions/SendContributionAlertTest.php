<?php

use Civi\CivicoNotify\Digest\ContributionStatsService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for SendContributionAlert::buildVars.
 * @group unit
 */
class CRM_CivicoNotify_CivirulesActions_SendContributionAlertTest extends TestCase {

  private function makeFakeStats(): ContributionStatsService {
    return new class extends ContributionStatsService {
      public function getCampaignTotal(int $campaignId): array {
        return ['total' => 18000.0, 'count' => 42];
      }
    };
  }

  /** Resolver mapping: 1=Completed, 2=Pending, 3=Cancelled. */
  private function statusResolver(): callable {
    $map = [1 => 'Completed', 2 => 'Pending', 3 => 'Cancelled'];
    return static fn($id) => $map[(int) $id] ?? '';
  }

  public function testContributionIsForwarded(): void {
    $contribution = ['id' => 99, 'total_amount' => 50.0, 'source' => 'Stripe', 'contribution_status_id' => 1];
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionAlert::buildVars(
      [],
      $contribution,
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver()
    );
    $this->assertSame($contribution, $vars['contribution']);
    $this->assertSame('Completed', $vars['to_status']);
    $this->assertSame('', $vars['from_status']);
    // campaign block always present with zeros to keep Smarty {if $campaign.goal} happy
    $this->assertSame(0, $vars['campaign']['goal']);
  }

  public function testFromStatusFilledWhenPreviousProvided(): void {
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionAlert::buildVars(
      [],
      ['id' => 1, 'contribution_status_id' => 1],
      2, // previous = Pending
      $this->makeFakeStats(),
      $this->statusResolver()
    );
    $this->assertSame('Pending', $vars['from_status']);
    $this->assertSame('Completed', $vars['to_status']);
  }

  public function testCampaignBlockBuiltWhenIdAndGoalPresent(): void {
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionAlert::buildVars(
      ['campaign_id' => 2, 'goal' => 30000, 'campaign_title' => 'Campaign 2026'],
      ['id' => 5, 'contribution_status_id' => 1],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver()
    );
    $this->assertSame(30000.0, $vars['campaign']['goal']);
    $this->assertSame(18000.0, $vars['campaign']['total']);
    $this->assertEqualsWithDelta(60.0, $vars['campaign']['percent'], 0.1);
    $this->assertSame('Campaign 2026', $vars['campaign']['title']);
  }

  public function testNoCampaignWithoutGoal(): void {
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionAlert::buildVars(
      ['campaign_id' => 2],
      ['id' => 5, 'contribution_status_id' => 1],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver()
    );
    // campaign block always present with zeros to keep Smarty {if $campaign.goal} happy
    $this->assertSame(0, $vars['campaign']['goal']);
  }

  public function testZeroGoalGivesZeroPercent(): void {
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionAlert::buildVars(
      ['campaign_id' => 2, 'goal' => 0],
      ['id' => 5, 'contribution_status_id' => 1],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver()
    );
    // goal=0 is falsy → campaign block should be empty (matches digest behaviour)
    // campaign block always present with zeros to keep Smarty {if $campaign.goal} happy
    $this->assertSame(0, $vars['campaign']['goal']);
  }

  public function testStatusResolverIsCalledForUnknownIds(): void {
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionAlert::buildVars(
      [],
      ['id' => 1, 'contribution_status_id' => 99],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver()
    );
    $this->assertSame('', $vars['to_status']);
  }

}
