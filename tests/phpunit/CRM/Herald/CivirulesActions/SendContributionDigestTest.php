<?php

declare(strict_types = 1);

use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Digest\DateRange;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for SendContributionDigest::buildVars — no Civi headless needed.
 * The action's processAction() path (which calls dispatch + Telegram) is covered
 * separately by an integration test against civikitchen.
 *
 * @group unit
 */
class CRM_Herald_CivirulesActions_SendContributionDigestTest extends TestCase {

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

      public function getAmountDistribution(array $filters): array {
        return [10.0, 20, 25, 50, 100, 100, 250, 500, 1000, 2000, 25, 30, 45, 80, 60, 90, 5, 15, 200, 300];
      }

      public function getCampaignTotal(int $campaignId): array {
        return ['total' => 25_000.0, 'count' => 50];
      }

    };
  }

  private function baseParams(): array {
    return [
      'channel' => 'telegram',
      'template' => 'herald_contribution_digest_daily',
      'period' => 'yesterday',
      'campaign_id' => 2,
      'show_pending' => TRUE,
      'show_histogram' => TRUE,
      'goal' => 30_000,
      'campaign_title' => 'Campaign 2026',
    ];
  }

  public function testTotalsAndPeriodAreSet(): void {
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($this->baseParams(), $this->makeFakeStats());
    $this->assertSame(1234.5, $vars['totals']['amount']);
    $this->assertSame(7, $vars['totals']['count']);
    $this->assertNotEmpty($vars['period']['label']);
  }

  public function testPendingBlockTogglesWithFlag(): void {
    $stats = $this->makeFakeStats();
    $on = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($this->baseParams(), $stats);
    $this->assertSame(2, $on['pending']['count']);

    $off = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['show_pending' => FALSE]),
      $stats,
    );
    $this->assertSame(0, $off['pending']['count']);
  }

  public function testCampaignProgressIsComputed(): void {
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($this->baseParams(), $this->makeFakeStats());
    $this->assertSame(30_000.0, $vars['campaign']['goal']);
    $this->assertSame(25_000.0, $vars['campaign']['total']);
    $this->assertEqualsWithDelta(83.3, $vars['campaign']['percent'], 0.1);
    $this->assertSame('Campaign 2026', $vars['campaign']['title']);
  }

  public function testNoGoalProducesZeroedCampaignBlock(): void {
    $params = $this->baseParams();
    unset($params['goal']);
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($params, $this->makeFakeStats());
    // campaign block is always populated with zeros so Smarty's {if $campaign.goal}
    // doesn't trip an "undefined key" notice on PHP 8+.
    $this->assertSame(0, $vars['campaign']['goal']);
    $this->assertSame(0, $vars['campaign']['percent']);
  }

  public function testHistogramFromAdaptiveBuckets(): void {
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($this->baseParams(), $this->makeFakeStats());
    $this->assertNotEmpty($vars['histogram']);
    $this->assertStringContainsString('█', $vars['histogram']);
  }

  public function testHistogramHonoursOverrideEdges(): void {
    $params = array_merge($this->baseParams(), ['bucket_edges' => '[0, 50, 200, 5000]']);
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($params, $this->makeFakeStats());
    $this->assertNotEmpty($vars['histogram']);
    $lines = explode("\n", $vars['histogram']);
    // 3 buckets when 4 edges supplied
    $this->assertCount(3, $lines);
  }

  public function testShowHistogramFalseSkipsHistogram(): void {
    $params = array_merge($this->baseParams(), ['show_histogram' => FALSE]);
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($params, $this->makeFakeStats());
    $this->assertSame('', $vars['histogram']);
  }

  public function testPeriodResolution(): void {
    $today = (new DateTime('today'))->format('Y-m-d');
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['period' => 'today']),
      $this->makeFakeStats(),
    );
    $this->assertSame($today, $vars['period']['label']);

    $varsWeek = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['period' => 'last_7_days']),
      $this->makeFakeStats(),
    );
    $this->assertSame('last 7 days', $varsWeek['period']['label']);

    $varsMonth = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['period' => 'last_30_days']),
      $this->makeFakeStats(),
    );
    $this->assertSame('last 30 days', $varsMonth['period']['label']);

    $varsUnknown = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['period' => 'wat']),
      $this->makeFakeStats(),
    );
    // unknown period falls back to yesterday
    $yesterday = (new DateTime('yesterday'))->format('Y-m-d');
    $this->assertSame($yesterday, $varsUnknown['period']['label']);
  }

  public function testCampaignFallbackUsesPeriodTotalsWhenNoCampaignId(): void {
    $params = $this->baseParams();
    unset($params['campaign_id']);
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($params, $this->makeFakeStats());
    $this->assertSame(1234.5, $vars['campaign']['total']);
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

      public function getAmountDistribution(array $filters): array {
        return [];
      }

      public function getCampaignTotal(int $campaignId): array {
        return ['total' => 0.0, 'count' => 0];
      }

    };
    $params = array_merge($this->baseParams(), ['group_by' => 'line_item', 'show_histogram' => FALSE]);
    unset($params['goal']);
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($params, $stats);
    $this->assertSame(415.0, $vars['totals']['amount']);
    $this->assertSame(9, $vars['totals']['count']);
    $this->assertCount(2, $vars['by_item']);
    $this->assertSame([], $vars['by_source']);
    $this->assertSame(120.0, $vars['pending']['amount']);
    $this->assertSame(2, $vars['pending']['count']);
    $this->assertCount(1, $vars['pending_items']);
  }

  public function testDefaultModeLeavesItemVarsEmpty(): void {
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($this->baseParams(), $this->makeFakeStats());
    $this->assertSame([], $vars['by_item']);
    $this->assertSame([], $vars['pending_items']);
  }

  public function testLegacyEmptyCampaignIdFallsBackToPeriodTotals(): void {
    // Legacy JSON-era params could store campaign_id as '' — that must behave
    // like "no campaign", not become getCampaignTotal(0).
    $stats = new class extends ContributionStatsService {

      public function getPeriodSummary(array $filters, DateRange $period): array {
        return ['total' => 1234.5, 'count' => 7, 'bySource' => []];
      }

      public function getAllPending(array $filters): array {
        return ['total' => 0.0, 'count' => 0];
      }

      public function getAmountDistribution(array $filters): array {
        return [10.0];
      }

      public function getCampaignTotal(int $campaignId): array {
        throw new \LogicException('getCampaignTotal must not be called for an empty campaign_id');
      }

    };
    $params = array_merge($this->baseParams(), ['campaign_id' => '']);
    $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($params, $stats);
    $this->assertSame(1234.5, $vars['campaign']['total']);
  }

}
