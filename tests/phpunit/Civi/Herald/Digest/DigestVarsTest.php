<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the digest variable bag — no Civi headless needed.
 *
 * @group unit
 */
class DigestVarsTest extends TestCase {

  private function makeFakeStats(): ContributionStatsService {
    return new class extends ContributionStatsService {

      public function getPeriodSummary(array $filters, DateRange $period): array {
        return [
          'total' => 1234.5,
          'count' => 7,
          'bySource' => [['source' => 'Stripe', 'total' => 1234.5, 'count' => 7]],
        ];
      }

      public function getAllPending(array $filters): array {
        return ['total' => 88.0, 'count' => 2];
      }

      public function getAmountDistribution(array $filters, DateRange $period): array {
        return [10.0, 20, 25, 50, 100, 100, 250, 500, 1000, 2000, 25, 30, 45, 80, 60, 90, 5, 15, 200, 300];
      }

      public function getCampaignTotal(array $filters): array {
        return ['total' => 25_000.0, 'count' => 50];
      }

    };
  }

  /**
   * Campaign 2 has a goal, campaign 3 none.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  private function build(array $params, ContributionStatsService $stats): array {
    $campaigns = [
      2 => ['title' => 'Campaign 2026', 'goal' => 30_000.0],
      3 => ['title' => 'No goal', 'goal' => 0.0],
      4 => ['title' => 'Passes', 'goal' => 6000.0],
    ];
    return DigestVars::build($params, $stats, static fn (int $id): ?array => $campaigns[$id] ?? NULL);
  }

  private function baseParams(): array {
    return [
      'channel' => 'telegram',
      'template' => 'herald_contribution_digest_daily',
      'period' => 'yesterday',
      'campaign_id' => 2,
      'show_pending' => TRUE,
      'show_histogram' => TRUE,
    ];
  }

  public function testAFailingCampaignTotalFailsTheDigest(): void {
    $stats = new class extends ContributionStatsService {

      public function getPeriodSummary(array $filters, DateRange $period): array {
        return ['total' => 0.0, 'count' => 0, 'bySource' => []];
      }

      public function getAllPending(array $filters): array {
        return ['total' => 0.0, 'count' => 0];
      }

      public function getAmountDistribution(array $filters, DateRange $period): array {
        return [];
      }

      public function getCampaignTotal(array $filters): array {
        throw new \RuntimeException('database gone');
      }

    };
    $this->expectExceptionMessage('database gone');
    $this->build($this->baseParams(), $stats);
  }

  public function testTotalsAndPeriodAreSet(): void {
    $vars = $this->build($this->baseParams(), $this->makeFakeStats());
    $this->assertSame(1234.5, $vars['totals']['amount']);
    $this->assertSame(7, $vars['totals']['count']);
    $this->assertNotEmpty($vars['period']['label']);
  }

  public function testPendingBlockTogglesWithFlag(): void {
    $stats = $this->makeFakeStats();
    $on = $this->build($this->baseParams(), $stats);
    $this->assertSame(2, $on['pending']['count']);

    $off = $this->build(
      array_merge($this->baseParams(), ['show_pending' => FALSE]),
      $stats,
    );
    $this->assertSame(0, $off['pending']['count']);
  }

  public function testCampaignProgressIsComputed(): void {
    $vars = $this->build($this->baseParams(), $this->makeFakeStats());
    $this->assertSame(30_000.0, $vars['campaign']['goal']);
    $this->assertSame(25_000.0, $vars['campaign']['total']);
    $this->assertEqualsWithDelta(83.3, $vars['campaign']['percent'], 0.1);
    $this->assertSame('Campaign 2026', $vars['campaign']['title']);
  }

  public function testCampaignWithoutGoalHasNoPercent(): void {
    $vars = $this->build(array_merge($this->baseParams(), ['campaign_id' => 3]), $this->makeFakeStats());
    $this->assertSame('No goal', $vars['campaign']['title']);
    $this->assertSame(0.0, $vars['campaign']['goal']);
    $this->assertSame(25_000.0, $vars['campaign']['total']);
    $this->assertSame(0, $vars['campaign']['percent']);
  }

  public function testHistogramFromAdaptiveBuckets(): void {
    $vars = $this->build($this->baseParams(), $this->makeFakeStats());
    $this->assertNotEmpty($vars['histogram']);
    $this->assertStringContainsString('█', $vars['histogram']);
  }

  public function testHistogramHonoursOverrideEdges(): void {
    $params = array_merge($this->baseParams(), ['bucket_edges' => '[0, 50, 200, 5000]']);
    $vars = $this->build($params, $this->makeFakeStats());
    $this->assertNotEmpty($vars['histogram']);
    $lines = explode("\n", $vars['histogram']);
    // 3 buckets when 4 edges supplied
    $this->assertCount(3, $lines);
  }

  public function testShowHistogramFalseSkipsHistogram(): void {
    $params = array_merge($this->baseParams(), ['show_histogram' => FALSE]);
    $vars = $this->build($params, $this->makeFakeStats());
    $this->assertSame('', $vars['histogram']);
  }

  public function testPeriodResolution(): void {
    $today = (new \DateTime('today'))->format('Y-m-d');
    $vars = $this->build(
      array_merge($this->baseParams(), ['period' => 'today']),
      $this->makeFakeStats(),
    );
    $this->assertSame($today, $vars['period']['label']);

    $varsWeek = $this->build(
      array_merge($this->baseParams(), ['period' => 'last_7_days']),
      $this->makeFakeStats(),
    );
    $this->assertSame('last 7 days', $varsWeek['period']['label']);

    $varsMonth = $this->build(
      array_merge($this->baseParams(), ['period' => 'last_30_days']),
      $this->makeFakeStats(),
    );
    $this->assertSame('last 30 days', $varsMonth['period']['label']);
  }

  public function testAnUnknownPeriodFails(): void {
    $this->expectExceptionMessage('unknown digest period "wat"');
    $this->build(array_merge($this->baseParams(), ['period' => 'wat']), $this->makeFakeStats());
  }

  public function testNoCampaignIdLeavesTheCampaignBlockEmpty(): void {
    $params = $this->baseParams();
    unset($params['campaign_id']);
    $vars = $this->build($params, $this->makeFakeStats());
    // Always present, so Smarty's {if $campaign.goal} finds the key.
    $this->assertSame(['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0], $vars['campaign']);
  }

  public function testGroupByLineItemDerivesTotalsFromItems(): void {
    $stats = new class extends ContributionStatsService {

      public function getItemSummary(array $filters, ?DateRange $period, ?string $status = NULL): array {
        if ($period === NULL) {
          // Pending pool (no date bound)
          return [['label' => 'Einzel – normal', 'unit_price' => 60.0, 'qty' => 2, 'total' => 120.0]];
        }
        return [
          ['label' => 'Einzel – normal', 'unit_price' => 60.0, 'qty' => 4, 'total' => 240.0],
          ['label' => 'Einzel – ermäßigt', 'unit_price' => 35.0, 'qty' => 5, 'total' => 175.0],
        ];
      }

      public function getPeriodSummary(array $filters, DateRange $period): array {
        throw new \LogicException('by-source query must not run in line_item mode');
      }

      public function getAllPending(array $filters): array {
        throw new \LogicException('contribution-level pending must not run in line_item mode');
      }

      public function getAmountDistribution(array $filters, DateRange $period): array {
        return [];
      }

      public function getCampaignTotal(array $filters): array {
        return ['total' => 0.0, 'count' => 0];
      }

    };
    $params = array_merge($this->baseParams(), [
      'group_by' => 'line_item',
      'show_histogram' => FALSE,
      'campaign_id' => 3,
    ]);
    $vars = $this->build($params, $stats);
    $this->assertSame(415.0, $vars['totals']['amount']);
    $this->assertSame(9, $vars['totals']['count']);
    $this->assertCount(2, $vars['by_item']);
    $this->assertSame([], $vars['by_source']);
    $this->assertSame(120.0, $vars['pending']['amount']);
    $this->assertSame(2, $vars['pending']['count']);
    $this->assertCount(1, $vars['pending_items']);
  }

  public function testLineItemCampaignProgressCountsCompletedItemsAtAnyDate(): void {
    $stats = new class extends ContributionStatsService {

      public function getItemSummary(array $filters, ?DateRange $period, ?string $status = NULL): array {
        if ($period === NULL && $status === 'Completed') {
          return [['label' => 'Pass', 'unit_price' => 100.0, 'qty' => 30, 'total' => 3000.0]];
        }
        return [];
      }

      public function getCampaignTotal(array $filters): array {
        throw new \LogicException('contribution-level campaign total must not run in line_item mode');
      }

    };
    $params = array_merge($this->baseParams(), [
      'group_by' => 'line_item',
      'show_histogram' => FALSE,
      'show_pending' => FALSE,
      'campaign_id' => 4,
    ]);
    $vars = $this->build($params, $stats);
    $this->assertSame(3000.0, $vars['campaign']['total']);
    $this->assertSame(30, $vars['campaign']['count']);
    $this->assertEqualsWithDelta(50.0, $vars['campaign']['percent'], 0.01);
  }

  public function testDefaultModeLeavesItemVarsEmpty(): void {
    $vars = $this->build($this->baseParams(), $this->makeFakeStats());
    $this->assertSame([], $vars['by_item']);
    $this->assertSame([], $vars['pending_items']);
  }

  public function testEmptyCampaignIdMeansNoCampaign(): void {
    // campaign_id stored as '' must mean "no campaign", not getCampaignTotal(0).
    $stats = new class extends ContributionStatsService {

      public function getPeriodSummary(array $filters, DateRange $period): array {
        return ['total' => 1234.5, 'count' => 7, 'bySource' => []];
      }

      public function getAllPending(array $filters): array {
        return ['total' => 0.0, 'count' => 0];
      }

      public function getAmountDistribution(array $filters, DateRange $period): array {
        return [10.0];
      }

      public function getCampaignTotal(array $filters): array {
        throw new \LogicException('getCampaignTotal must not be called for an empty campaign_id');
      }

    };
    $params = array_merge($this->baseParams(), ['campaign_id' => '']);
    $vars = $this->build($params, $stats);
    $this->assertSame('', $vars['campaign']['title']);
  }

}
