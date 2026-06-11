<?php

namespace Civi\Herald\Digest;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class ContributionStatsServiceTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private int $contactId;
  private ContributionStatsService $service;

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      // Campaign tests need the CiviCampaign component (off in a minimal
      // headless install).
      ->callback(function() {
        \CRM_Core_BAO_ConfigSetting::enableComponent('CiviCampaign');
      }, 'enableCiviCampaign')
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    // Wipe demo contributions so test fixtures are the only rows the queries
    // see. TransactionalInterface rolls these DELETEs back after each test, so
    // other classes still find the demo data.
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_contribution');
    $this->contactId = (int) Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Stats')
      ->addValue('last_name', 'Test')
      ->execute()->first()['id'];
    $this->service = new ContributionStatsService();
  }

  /**
   * @param array<int, array{amount: float, status?: string, source?: string, receive_date?: string, modified_date?: string}> $rows
   */
  private function seedContributions(array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
      $id = (int) Contribution::create(FALSE)
        ->addValue('contact_id', $this->contactId)
        ->addValue('financial_type_id:name', 'Donation')
        ->addValue('contribution_status_id:name', $row['status'] ?? 'Completed')
        ->addValue('total_amount', $row['amount'])
        ->addValue('source', $row['source'] ?? 'Stripe')
        ->addValue('receive_date', $row['receive_date'] ?? date('Y-m-d H:i:s'))
        ->execute()->first()['id'];
      if (isset($row['modified_date'])) {
        // modified_date is normally auto-populated; force it for transition tests.
        \CRM_Core_DAO::executeQuery(
          'UPDATE civicrm_contribution SET modified_date = %1 WHERE id = %2',
          [
            1 => [$row['modified_date'], 'String'],
            2 => [$id, 'Integer'],
          ]
        );
      }
      $ids[] = $id;
    }
    return $ids;
  }

  public function testGetPeriodSummaryReturnsCorrectTotals(): void {
    $yesterday = date('Y-m-d 12:00:00', strtotime('yesterday'));
    $this->seedContributions([
      ['amount' => 10.0, 'source' => 'Stripe', 'receive_date' => $yesterday],
      ['amount' => 20.0, 'source' => 'Stripe', 'receive_date' => $yesterday],
      ['amount' => 50.0, 'source' => 'Bank', 'receive_date' => $yesterday],
    ]);
    $summary = $this->service->getPeriodSummary([], DateRange::yesterday());

    $this->assertSame(80.0, $summary['total']);
    $this->assertSame(3, $summary['count']);
    $this->assertCount(2, $summary['bySource']);

    $bySource = [];
    foreach ($summary['bySource'] as $row) {
      $bySource[$row['source']] = ['total' => $row['total'], 'count' => $row['count']];
    }
    $this->assertSame(['total' => 30.0, 'count' => 2], $bySource['Stripe']);
    $this->assertSame(['total' => 50.0, 'count' => 1], $bySource['Bank']);
  }

  public function testGetAllPendingExcludesCompleted(): void {
    $this->seedContributions([
      ['amount' => 100.0, 'status' => 'Completed'],
      ['amount' => 200.0, 'status' => 'Pending'],
      ['amount' => 50.0, 'status' => 'Pending'],
    ]);
    $pending = $this->service->getAllPending([]);
    $this->assertSame(250.0, $pending['total']);
    $this->assertSame(2, $pending['count']);
  }

  public function testFiltersByCampaignId(): void {
    // Create a campaign first
    $campaignId = (int) \Civi\Api4\Campaign::create(FALSE)
      ->addValue('title', 'TestCampaign')
      ->addValue('campaign_type_id', 1)
      ->execute()->first()['id'];

    $yesterday = date('Y-m-d 12:00:00', strtotime('yesterday'));
    Contribution::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('contribution_status_id:name', 'Completed')
      ->addValue('total_amount', 100.0)
      ->addValue('campaign_id', $campaignId)
      ->addValue('receive_date', $yesterday)
      ->execute();
    Contribution::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('contribution_status_id:name', 'Completed')
      ->addValue('total_amount', 50.0)
      ->addValue('receive_date', $yesterday)
      ->execute();

    $withCampaign = $this->service->getPeriodSummary(['campaign_id' => $campaignId], DateRange::yesterday());
    $this->assertSame(100.0, $withCampaign['total']);
    $this->assertSame(1, $withCampaign['count']);

    $allDonations = $this->service->getPeriodSummary([], DateRange::yesterday());
    $this->assertSame(150.0, $allDonations['total']);
  }

  public function testFiltersBySourcePattern(): void {
    $today = date('Y-m-d 12:00:00', strtotime('yesterday'));
    $this->seedContributions([
      ['amount' => 10.0, 'source' => 'BB-TX-12345', 'receive_date' => $today],
      ['amount' => 20.0, 'source' => 'Stripe', 'receive_date' => $today],
    ]);
    $bb = $this->service->getPeriodSummary(['source_pattern' => 'BB-TX-%'], DateRange::yesterday());
    $this->assertSame(10.0, $bb['total']);
  }

  public function testGetAmountDistributionReturnsAllAmounts(): void {
    $this->seedContributions([
      ['amount' => 5.0],
      ['amount' => 15.0],
      ['amount' => 100.0],
    ]);
    $amounts = $this->service->getAmountDistribution([]);
    sort($amounts);
    $this->assertSame([5.0, 15.0, 100.0], $amounts);
  }

  public function testGetRecentCompletedReturnsRowsInDateOrder(): void {
    $now = time();
    $this->seedContributions([
      ['amount' => 10.0, 'receive_date' => date('Y-m-d H:i:s', $now - 60)],
      ['amount' => 20.0, 'receive_date' => date('Y-m-d H:i:s', $now - 120)],
      ['amount' => 30.0, 'receive_date' => date('Y-m-d H:i:s', $now - 30)],
    ]);
    $recent = $this->service->getRecentCompleted([], 10);
    $this->assertCount(3, $recent);
    // ordered DESC by receive_date — newest first
    $this->assertSame(30.0, $recent[0]['amount']);
    $this->assertSame(10.0, $recent[1]['amount']);
    $this->assertSame(20.0, $recent[2]['amount']);
  }

  public function testGetRecentPendingOnlyReturnsPending(): void {
    $now = date('Y-m-d H:i:s');
    $this->seedContributions([
      ['amount' => 10.0, 'status' => 'Pending', 'receive_date' => $now],
      ['amount' => 20.0, 'status' => 'Completed', 'receive_date' => $now],
    ]);
    $pending = $this->service->getRecentPending([], 10);
    $this->assertCount(1, $pending);
    $this->assertSame(10.0, $pending[0]['amount']);
  }

  public function testGetCampaignTotalSumsCompletedOnly(): void {
    $campaignId = (int) \Civi\Api4\Campaign::create(FALSE)
      ->addValue('title', 'TotalCampaign')
      ->addValue('campaign_type_id', 1)
      ->execute()->first()['id'];

    Contribution::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('contribution_status_id:name', 'Completed')
      ->addValue('total_amount', 1000.0)
      ->addValue('campaign_id', $campaignId)
      ->execute();
    Contribution::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('total_amount', 500.0)
      ->addValue('campaign_id', $campaignId)
      ->execute();

    $total = $this->service->getCampaignTotal($campaignId);
    $this->assertSame(1000.0, $total['total']);
    $this->assertSame(1, $total['count']);
  }

  public function testGetRecentStatusTransitions(): void {
    $now = date('Y-m-d H:i:s');
    $this->seedContributions([
      // Genuine transition: received long ago, modified just now.
      ['amount' => 100.0, 'status' => 'Completed', 'receive_date' => date('Y-m-d H:i:s', strtotime('-1 day')), 'modified_date' => $now],
      // Outside the window: modified 2 hours ago.
      ['amount' => 200.0, 'status' => 'Completed', 'receive_date' => date('Y-m-d H:i:s', strtotime('-1 day')), 'modified_date' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
    ]);
    $recent = $this->service->getRecentStatusTransitions([], 10, 'Completed');
    $this->assertCount(1, $recent);
    $this->assertSame(100.0, $recent[0]['amount']);
  }

  public function testGetRecentStatusTransitionsExcludesFreshlyReceivedByDefault(): void {
    $now = date('Y-m-d H:i:s');
    $this->seedContributions([
      // Received AND modified inside the window — that's a new contribution,
      // not a transition; getPeriodSummary already counts it.
      ['amount' => 300.0, 'status' => 'Completed', 'receive_date' => $now, 'modified_date' => $now],
    ]);
    $this->assertCount(0, $this->service->getRecentStatusTransitions([], 10, 'Completed'));
    // Opt-out returns it.
    $all = $this->service->getRecentStatusTransitions([], 10, 'Completed', FALSE);
    $this->assertCount(1, $all);
    $this->assertSame(300.0, $all[0]['amount']);
  }

}
