<?php

use Civi\CivicoNotify\Digest\ContributionStatsService;
use Civi\CivicoNotify\Digest\DateRange;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for SendContributionDigest::buildVars — no Civi headless needed.
 * The action's processAction() path (which calls dispatch + Telegram) is covered
 * separately by an integration test against civikitchen.
 *
 * @group unit
 */
class CRM_CivicoNotify_CivirulesActions_SendContributionDigestTest extends TestCase {

  private function makeFakeStats(): ContributionStatsService {
    return new class extends ContributionStatsService {
      public function getPeriodSummary(array $filters, DateRange $period): array {
        return ['total' => 1234.5, 'count' => 7, 'bySource' => [['source' => 'Stripe', 'total' => 1234.5, 'count' => 7]]];
      }
      public function getAllPending(array $filters): array { return ['total' => 88.0, 'count' => 2]; }
      public function getAmountDistribution(array $filters): array {
        return [10.0, 20, 25, 50, 100, 100, 250, 500, 1000, 2000, 25, 30, 45, 80, 60, 90, 5, 15, 200, 300];
      }
      public function getCampaignTotal(int $campaignId): array { return ['total' => 25000.0, 'count' => 50]; }
    };
  }

  private function baseParams(): array {
    return [
      'channel' => 'telegram',
      'template' => 'civico_notify_contribution_digest_daily',
      'period' => 'yesterday',
      'campaign_id' => 2,
      'show_pending' => TRUE,
      'show_histogram' => TRUE,
      'goal' => 30000,
      'campaign_title' => 'Campaign 2026',
    ];
  }

  public function testTotalsAndPeriodAreSet(): void {
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars($this->baseParams(), $this->makeFakeStats());
    $this->assertSame(1234.5, $vars['totals']['amount']);
    $this->assertSame(7, $vars['totals']['count']);
    $this->assertNotEmpty($vars['period']['label']);
  }

  public function testPendingBlockTogglesWithFlag(): void {
    $stats = $this->makeFakeStats();
    $on = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars($this->baseParams(), $stats);
    $this->assertSame(2, $on['pending']['count']);

    $off = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['show_pending' => FALSE]),
      $stats
    );
    $this->assertSame(0, $off['pending']['count']);
  }

  public function testCampaignProgressIsComputed(): void {
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars($this->baseParams(), $this->makeFakeStats());
    $this->assertSame(30000.0, $vars['campaign']['goal']);
    $this->assertSame(25000.0, $vars['campaign']['total']);
    $this->assertEqualsWithDelta(83.3, $vars['campaign']['percent'], 0.1);
    $this->assertSame('Campaign 2026', $vars['campaign']['title']);
  }

  public function testNoGoalProducesZeroedCampaignBlock(): void {
    $params = $this->baseParams();
    unset($params['goal']);
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars($params, $this->makeFakeStats());
    // campaign block is always populated with zeros so Smarty's {if $campaign.goal}
    // doesn't trip an "undefined key" notice on PHP 8+.
    $this->assertSame(0, $vars['campaign']['goal']);
    $this->assertSame(0, $vars['campaign']['percent']);
  }

  public function testHistogramFromAdaptiveBuckets(): void {
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars($this->baseParams(), $this->makeFakeStats());
    $this->assertNotEmpty($vars['histogram']);
    $this->assertStringContainsString('█', $vars['histogram']);
  }

  public function testHistogramHonoursOverrideEdges(): void {
    $params = array_merge($this->baseParams(), ['bucket_edges' => '[0, 50, 200, 5000]']);
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars($params, $this->makeFakeStats());
    $this->assertNotEmpty($vars['histogram']);
    $lines = explode("\n", $vars['histogram']);
    // 3 buckets when 4 edges supplied
    $this->assertCount(3, $lines);
  }

  public function testShowHistogramFalseSkipsHistogram(): void {
    $params = array_merge($this->baseParams(), ['show_histogram' => FALSE]);
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars($params, $this->makeFakeStats());
    $this->assertSame('', $vars['histogram']);
  }

  public function testPeriodResolution(): void {
    $today = (new DateTime('today'))->format('Y-m-d');
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['period' => 'today']),
      $this->makeFakeStats()
    );
    $this->assertSame($today, $vars['period']['label']);

    $varsWeek = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['period' => 'last_7_days']),
      $this->makeFakeStats()
    );
    $this->assertSame('last 7 days', $varsWeek['period']['label']);

    $varsMonth = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['period' => 'last_30_days']),
      $this->makeFakeStats()
    );
    $this->assertSame('last 30 days', $varsMonth['period']['label']);

    $varsUnknown = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars(
      array_merge($this->baseParams(), ['period' => 'wat']),
      $this->makeFakeStats()
    );
    // unknown period falls back to yesterday
    $yesterday = (new DateTime('yesterday'))->format('Y-m-d');
    $this->assertSame($yesterday, $varsUnknown['period']['label']);
  }

  public function testCampaignFallbackUsesPeriodTotalsWhenNoCampaignId(): void {
    $params = $this->baseParams();
    unset($params['campaign_id']);
    $vars = CRM_CivicoNotify_CivirulesActions_SendContributionDigest::buildVars($params, $this->makeFakeStats());
    $this->assertSame(1234.5, $vars['campaign']['total']);
  }

}
