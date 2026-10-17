<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A contribution's campaign block, read from the stored Campaign record.
 *
 * @group headless
 */
class CampaignProgressTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testTheProgressReadsTitleAndGoalOfTheCampaign(): void {
    $id = \Civi\Api4\Campaign::create(checkPermissions: FALSE)
      ->setValues(['name' => 'herald_test_campaign', 'title' => 'Herald test campaign', 'goal_revenue' => 2000])
      ->execute()
      ->single()['id'];

    $progress = CampaignProgress::of(
      $id,
      static fn (): array => ['total' => 500.0, 'count' => 3],
      CampaignProgress::resolveCampaign(...),
    );

    self::assertSame(
      ['title' => 'Herald test campaign', 'goal' => 2000.0, 'total' => 500.0, 'count' => 3, 'percent' => 25.0],
      $progress,
    );
  }

  public function testACampaignWithoutGoalHasNoPercentage(): void {
    $id = \Civi\Api4\Campaign::create(checkPermissions: FALSE)
      ->setValues(['name' => 'herald_test_no_goal', 'title' => 'No goal'])
      ->execute()
      ->single()['id'];

    self::assertSame(['title' => 'No goal', 'goal' => 0.0], CampaignProgress::resolveCampaign($id));
    self::assertSame(
      0,
      CampaignProgress::of(
        $id,
        static fn (): array => ['total' => 10.0, 'count' => 1],
        CampaignProgress::resolveCampaign(...),
      )['percent'],
    );
  }

  public function testAVanishedCampaignGivesTheEmptyBlock(): void {
    self::assertNull(CampaignProgress::resolveCampaign(999_999_999));
    self::assertSame(
      CampaignProgress::EMPTY,
      CampaignProgress::of(
        999_999_999,
        static fn (): array => ['total' => 0.0, 'count' => 0],
        CampaignProgress::resolveCampaign(...),
      ),
    );
  }

  /**
   * Pending payments, contribution-page test drives and recurring templates are no income.
   */
  public function testTheTotalCountsCompletedContributionsOnly(): void {
    $campaignId = \Civi\Api4\Campaign::create(checkPermissions: FALSE)
      ->setValues(['name' => 'herald_test_total', 'title' => 'Total'])
      ->execute()
      ->single()['id'];
    $contactId = \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Individual', 'first_name' => 'Campaign', 'last_name' => 'Test'])
      ->execute()
      ->single()['id'];
    $rows = [
      [1000.0, 'Completed', []],
      [20.0, 'Completed', []],
      [500.0, 'Pending', []],
      [300.0, 'Completed', ['is_test' => TRUE]],
      [700.0, 'Completed', ['is_template' => TRUE]],
    ];
    foreach ($rows as [$amount, $status, $flags]) {
      \Civi\Api4\Contribution::create(checkPermissions: FALSE)
        ->setValues([
          'contact_id' => $contactId,
          'campaign_id' => $campaignId,
          'financial_type_id:name' => 'Donation',
          'contribution_status_id:name' => $status,
          'total_amount' => $amount,
        ] + $flags)
        ->execute();
    }

    self::assertSame(['total' => 1020.0, 'count' => 2], CampaignProgress::total($campaignId));
    self::assertSame(['total' => 0.0, 'count' => 0], CampaignProgress::total(999_999_999));
  }

}
