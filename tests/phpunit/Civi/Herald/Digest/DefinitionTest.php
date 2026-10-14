<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\HeraldDigest;
use Civi\Api4\HeraldDigestBlock;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A stored digest loads with its blocks in the order the form saved them.
 *
 * @group headless
 */
class DefinitionTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      ->callback(static function () {
        \CRM_Core_BAO_ConfigSetting::enableComponent('CiviCampaign');
      }, 'enableCiviCampaign')
      ->apply();
  }

  public function testTheBlocksComeInSaveOrderWithoutEmptyFields(): void {
    $id = HeraldDigest::create(checkPermissions: FALSE)
      ->setValues(['name' => 'weekly', 'title' => 'Weekly', 'header' => '<b>Week</b>', 'period' => 'last_7_days'])
      ->execute()
      ->single()['id'];
    HeraldDigestBlock::save(checkPermissions: FALSE)
      ->setDefaults(['digest_id' => $id])
      ->setRecords([
        ['type' => 'section', 'section_key' => 'myext.seats', 'title' => ''],
        ['type' => 'contribution', 'title' => 'Donations', 'bucket_count' => 4],
      ])
      ->execute();

    $definition = Definition::load('weekly');

    self::assertSame(['weekly', '<b>Week</b>', 'last_7_days'], [
      $definition['name'],
      $definition['header'],
      $definition['period'],
    ]);
    self::assertSame(['section', 'contribution'], array_column($definition['blocks'], 'type'));
    self::assertSame(['type' => 'section', 'section_key' => 'myext.seats'], array_intersect_key(
      $definition['blocks'][0],
      ['type' => 1, 'section_key' => 1, 'title' => 1, 'period' => 1, 'template' => 1],
    ));
    self::assertSame(['Donations', 4], [$definition['blocks'][1]['title'], $definition['blocks'][1]['bucket_count']]);
  }

  public function testACampaignABlockFiltersOnStays(): void {
    $campaign = \Civi\Api4\Campaign::create(checkPermissions: FALSE)
      ->setValues(['name' => 'herald_test_campaign', 'title' => 'Herald test campaign'])
      ->execute()
      ->single()['id'];
    HeraldDigestBlock::create(checkPermissions: FALSE)
      ->setValues([
        'digest_id' => HeraldDigest::create(checkPermissions: FALSE)
          ->setValues(['name' => 'weekly', 'title' => 'Weekly'])
          ->execute()
          ->single()['id'],
        'campaign_id' => $campaign,
      ])
      ->execute();

    // Without the filter the block would count every contribution.
    $this->expectExceptionMessage('constraint violation');
    \Civi\Api4\Campaign::delete(checkPermissions: FALSE)->addWhere('id', '=', $campaign)->execute();
  }

  public function testAnUnknownDigestFails(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Digest "missing" does not exist.');
    Definition::load('missing');
  }

  public function testTheFormGetsTheSchemaDefaultsOfABlock(): void {
    self::assertSame(
      [
        'type' => 'contribution',
        'group_by' => 'source',
        'show_pending' => TRUE,
        'show_histogram' => TRUE,
        'bucket_count' => 8,
      ],
      Options::angularSettings()['blockDefaults'],
    );
  }

}
