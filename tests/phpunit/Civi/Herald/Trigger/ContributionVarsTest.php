<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * The contribution template variables, with fake lookups. Headless: a
 * failing campaign lookup is logged.
 *
 * @group headless
 */
class ContributionVarsTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  /**
   * @var \ArrayObject<int, int>
   */
  private \ArrayObject $statsCalls;

  protected function setUp(): void {
    $this->statsCalls = new \ArrayObject();
  }

  /**
   * @return \Closure(int): array{total: float, count: int}
   */
  private function fakeCampaignTotal(): \Closure {
    $calls = $this->statsCalls;
    return static function (int $campaignId) use ($calls): array {
      $calls[] = $campaignId;
      return ['total' => 18_000.0, 'count' => 42];
    };
  }

  /**
   * Resolver mapping: 1=Completed, 2=Pending, 3=Cancelled. */
  private function statusResolver(): \Closure {
    $map = [1 => 'Completed', 2 => 'Pending', 3 => 'Cancelled'];
    return static fn ($id) => $map[(int) $id] ?? '';
  }

  /**
   * @param array<int, array{title: string, goal: float}> $campaigns
   */
  private function campaignResolver(array $campaigns): \Closure {
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
      new ContributionLookups(
        $this->fakeCampaignTotal(),
        $this->statusResolver(),
        $this->campaignResolver($campaigns),
        static fn (int $id): array => [],
      ),
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

  public function testCleanedFieldsReplaceTheRawValues(): void {
    $vars = ContributionVars::build(
      ['id' => 1, 'source' => 'Bank TX 123', 'contribution_status_id' => 1],
      NULL,
      new ContributionLookups(
        $this->fakeCampaignTotal(),
        $this->statusResolver(),
        $this->campaignResolver([]),
        static fn (int $id): array => $id === 1 ? ['source' => 'Transfer'] : [],
      ),
    );

    $this->assertSame('Transfer', $vars['contribution']['source']);
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
    $this->assertSame([2], $this->statsCalls->getArrayCopy());
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
    $failingTotal = static fn (int $campaignId): array => throw new \RuntimeException('read failed');
    $vars = ContributionVars::build(
      ['id' => 5, 'contribution_status_id' => 1, 'campaign_id' => 2],
      NULL,
      new ContributionLookups(
        $failingTotal,
        $this->statusResolver(),
        $this->campaignResolver([2 => ['title' => 'Campaign 2026', 'goal' => 30_000.0]]),
      ),
    );
    $this->assertSame(['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0], $vars['campaign']);
    $this->assertSame('Completed', $vars['to_status']);
  }

  public function testStatusResolverIsCalledForUnknownIds(): void {
    $vars = $this->build(['id' => 1, 'contribution_status_id' => 99]);
    $this->assertSame('', $vars['to_status']);
  }

}
