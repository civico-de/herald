<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Digest\SourceNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the contribution template variables.
 * @group unit
 */
class ContributionVarsTest extends TestCase {

  /**
   * @var \ArrayObject<int, array<string, mixed>>
   */
  private \ArrayObject $statsCalls;

  protected function setUp(): void {
    $this->statsCalls = new \ArrayObject();
  }

  private function makeFakeStats(): ContributionStatsService {
    return new class($this->statsCalls) extends ContributionStatsService {

      /**
       * @param \ArrayObject<int, array<string, mixed>> $calls
       */
      public function __construct(
        private \ArrayObject $calls,
      ) {}

      public function getCampaignTotal(array $filters): array {
        $this->calls[] = $filters;
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

  /**
   * @param array<int, array{title: string, goal: float}> $campaigns
   */
  private function campaignResolver(array $campaigns): callable {
    return static fn (int $id): ?array => $campaigns[$id] ?? NULL;
  }

  /**
   * @param array<array-key, mixed> $contribution
   * @return array<string, mixed>
   */
  private function build(array $contribution, ?int $previous = NULL, array $campaigns = []): array {
    return ContributionVars::build(
      $contribution,
      $previous,
      $this->makeFakeStats(),
      $this->statusResolver(),
      $this->campaignResolver($campaigns),
    );
  }

  public function testContributionIsForwarded(): void {
    $contribution = ['id' => 99, 'total_amount' => 50.0, 'source' => 'Stripe', 'contribution_status_id' => 1];
    $vars = $this->build($contribution);
    $this->assertSame($contribution, $vars['contribution']);
    $this->assertSame('Completed', $vars['to_status']);
    $this->assertSame('', $vars['from_status']);
    // campaign block always present with zeros to keep Smarty {if $campaign.goal} happy
    $this->assertSame(0, $vars['campaign']['goal']);
    $this->assertSame([], $this->statsCalls->getArrayCopy());
  }

  public function testFromStatusFilledWhenPreviousProvided(): void {
    $vars = $this->build(['id' => 1, 'contribution_status_id' => 1], previous: 2);
    $this->assertSame('Pending', $vars['from_status']);
    $this->assertSame('Completed', $vars['to_status']);
  }

  public function testContributionSourceCanBeNormalized(): void {
    $vars = ContributionVars::build(
      ['id' => 1, 'source' => 'Bank TX 123', 'contribution_status_id' => 1],
      NULL,
      $this->makeFakeStats(),
      $this->statusResolver(),
      $this->campaignResolver([]),
      new SourceNormalizer([
        ['match' => 'Bank TX', 'type' => 'contains', 'label' => 'Ueberweisung'],
      ]),
    );

    $this->assertSame('Ueberweisung', $vars['contribution']['source']);
  }

  public function testCampaignBlockComesFromTheContributionsCampaign(): void {
    $vars = $this->build(
      ['id' => 5, 'contribution_status_id' => 1, 'campaign_id' => 2],
      campaigns: [2 => ['title' => 'Campaign 2026', 'goal' => 30_000.0]],
    );
    $this->assertSame(30_000.0, $vars['campaign']['goal']);
    $this->assertSame(18_000.0, $vars['campaign']['total']);
    $this->assertSame(42, $vars['campaign']['count']);
    $this->assertEqualsWithDelta(60.0, $vars['campaign']['percent'], 0.1);
    $this->assertSame('Campaign 2026', $vars['campaign']['title']);
    $this->assertSame([['campaign_id' => 2]], $this->statsCalls->getArrayCopy());
  }

  public function testCampaignWithoutGoalKeepsTitleAndTotalsButNoPercent(): void {
    $vars = $this->build(
      ['id' => 5, 'contribution_status_id' => 1, 'campaign_id' => 3],
      campaigns: [3 => ['title' => 'No goal', 'goal' => 0.0]],
    );
    $this->assertSame('No goal', $vars['campaign']['title']);
    $this->assertSame(0.0, $vars['campaign']['goal']);
    $this->assertSame(18_000.0, $vars['campaign']['total']);
    $this->assertSame(0, $vars['campaign']['percent']);
  }

  public function testVanishedCampaignGivesTheEmptyBlock(): void {
    $vars = $this->build(['id' => 5, 'contribution_status_id' => 1, 'campaign_id' => 9]);
    $this->assertSame(['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0], $vars['campaign']);
    $this->assertSame([], $this->statsCalls->getArrayCopy());
  }

  public function testFailingCampaignTotalGivesTheEmptyBlock(): void {
    $failingStats = new class extends ContributionStatsService {

      public function __construct() {}

      public function getCampaignTotal(array $filters): array {
        throw new \RuntimeException('read failed');
      }

    };
    $vars = ContributionVars::build(
      ['id' => 5, 'contribution_status_id' => 1, 'campaign_id' => 2],
      NULL,
      $failingStats,
      $this->statusResolver(),
      $this->campaignResolver([2 => ['title' => 'Campaign 2026', 'goal' => 30_000.0]]),
    );
    $this->assertSame(['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0], $vars['campaign']);
    $this->assertSame('Completed', $vars['to_status']);
  }

  public function testStatusResolverIsCalledForUnknownIds(): void {
    $vars = $this->build(['id' => 1, 'contribution_status_id' => 99]);
    $this->assertSame('', $vars['to_status']);
  }

}
