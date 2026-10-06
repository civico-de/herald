<?php

declare(strict_types = 1);

namespace Civi\Herald\Alert;

use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Digest\SourceNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the contribution-alert variable bag.
 * @group unit
 */
class ContributionAlertVarsTest extends TestCase {

  private function makeFakeStats(): ContributionStatsService {
    return new class extends ContributionStatsService {

      public function getCampaignTotal(array $filters): array {
        return ['total' => 18_000.0, 'count' => 42];
      }

    };
  }

  /**
   * Resolver mapping: 1=Completed, 2=Pending, 3=Cancelled. */
  private function statusResolver(): callable {
    $map = [1 => 'Completed', 2 => 'Pending', 3 => 'Cancelled'];
    return static fn ($id) => $map[(int) $id] ?? '';
  }

  public function testContributionIsForwarded(): void {
    $contribution = ['id' => 99, 'total_amount' => 50.0, 'source' => 'Stripe', 'contribution_status_id' => 1];
    $vars = ContributionAlertVars::build(
      [],
      $contribution,
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver(),
    );
    $this->assertSame($contribution, $vars['contribution']);
    $this->assertSame('Completed', $vars['to_status']);
    $this->assertSame('', $vars['from_status']);
    // campaign block always present with zeros to keep Smarty {if $campaign.goal} happy
    $this->assertSame(0, $vars['campaign']['goal']);
  }

  public function testFromStatusFilledWhenPreviousProvided(): void {
    $vars = ContributionAlertVars::build(
      [],
      ['id' => 1, 'contribution_status_id' => 1],
      // previous = Pending
      2,
      $this->makeFakeStats(),
      $this->statusResolver(),
    );
    $this->assertSame('Pending', $vars['from_status']);
    $this->assertSame('Completed', $vars['to_status']);
  }

  public function testContributionSourceCanBeNormalized(): void {
    $vars = ContributionAlertVars::build(
      [],
      ['id' => 1, 'source' => 'Bank TX 123', 'contribution_status_id' => 1],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver(),
      new SourceNormalizer([
        ['match' => 'Bank TX', 'type' => 'contains', 'label' => 'Ueberweisung'],
      ]),
    );

    $this->assertSame('Ueberweisung', $vars['contribution']['source']);
  }

  public function testCampaignBlockBuiltWhenIdAndGoalPresent(): void {
    $vars = ContributionAlertVars::build(
      ['campaign_id' => 2, 'goal' => 30_000, 'campaign_title' => 'Campaign 2026'],
      ['id' => 5, 'contribution_status_id' => 1],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver(),
    );
    $this->assertSame(30_000.0, $vars['campaign']['goal']);
    $this->assertSame(18_000.0, $vars['campaign']['total']);
    $this->assertEqualsWithDelta(60.0, $vars['campaign']['percent'], 0.1);
    $this->assertSame('Campaign 2026', $vars['campaign']['title']);
  }

  public function testNoCampaignWithoutGoal(): void {
    $vars = ContributionAlertVars::build(
      ['campaign_id' => 2],
      ['id' => 5, 'contribution_status_id' => 1],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver(),
    );
    // campaign block always present with zeros to keep Smarty {if $campaign.goal} happy
    $this->assertSame(0, $vars['campaign']['goal']);
  }

  public function testZeroGoalGivesZeroPercent(): void {
    $vars = ContributionAlertVars::build(
      ['campaign_id' => 2, 'goal' => 0],
      ['id' => 5, 'contribution_status_id' => 1],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver(),
    );
    // goal=0 is falsy → campaign block should be empty (matches digest behaviour)
    // campaign block always present with zeros to keep Smarty {if $campaign.goal} happy
    $this->assertSame(0, $vars['campaign']['goal']);
  }

  public function testStatusResolverIsCalledForUnknownIds(): void {
    $vars = ContributionAlertVars::build(
      [],
      ['id' => 1, 'contribution_status_id' => 99],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver(),
    );
    $this->assertSame('', $vars['to_status']);
  }

}
