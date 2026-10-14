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
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->callback(static function () {
        \CRM_Core_BAO_ConfigSetting::enableComponent('CiviCampaign');
      }, 'enableCiviCampaign')
      ->apply();
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

}
