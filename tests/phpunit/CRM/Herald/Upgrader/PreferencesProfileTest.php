<?php

declare(strict_types = 1);

use Civi\Api4\CustomField;
use Civi\Api4\UFField;
use Civi\Api4\UFGroup;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The self-service preferences profile comes with the install and is
 * restored, without touching kept rows, by the upgrade step.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_PreferencesProfileTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  /**
   * @return array<string, string> field_name => label
   */
  private function fields(int $gid): array {
    /** @var array<string, string> */
    return UFField::get(checkPermissions: FALSE)
      ->addWhere('uf_group_id', '=', $gid)
      ->execute()
      ->column('label', 'field_name');
  }

  private function gid(): int {
    /** @var array{id: int} $group */
    $group = UFGroup::get(checkPermissions: FALSE)
      ->addSelect('id')
      ->addWhere('name', '=', CRM_Herald_Upgrader_PreferencesProfile::NAME)
      ->execute()
      ->single();
    return $group['id'];
  }

  public function testInstallCreatesTheProfileOnTheCustomFields(): void {
    /** @var array{id: int} $channel */
    $channel = CustomField::get(checkPermissions: FALSE)
      ->addSelect('id')
      ->addWhere('custom_group_id:name', '=', 'herald_preferences')
      ->addWhere('name', '=', 'preferred_channel')
      ->execute()
      ->single();

    $fields = $this->fields($this->gid());

    self::assertCount(6, $fields);
    self::assertSame('Preferred channel', $fields['custom_' . $channel['id']]);
  }

  public function testUpgradeRestoresMissingFieldsAndKeepsEditedOnes(): void {
    $gid = $this->gid();
    $fieldNames = array_keys($this->fields($gid));
    UFField::delete(checkPermissions: FALSE)->addWhere('field_name', '=', $fieldNames[0])->execute();
    UFField::update(checkPermissions: FALSE)
      ->addWhere('uf_group_id', '=', $gid)
      ->addWhere('field_name', '=', $fieldNames[1])
      ->addValue('label', 'Edited by an admin')
      ->execute();
    $ctx = new CRM_Queue_TaskContext();
    $ctx->log = CRM_Core_Error::createDebugLogger();

    self::assertTrue(CRM_Herald_Upgrader::_queueAdapter($ctx, 'herald', 'upgrade_1009'));

    self::assertSame($gid, $this->gid());
    $fields = $this->fields($gid);
    self::assertCount(6, $fields);
    self::assertSame('Edited by an admin', $fields[$fieldNames[1]]);
  }

  public function testUpgradeCreatesTheProfileWhereNoneExists(): void {
    UFGroup::delete(checkPermissions: FALSE)
      ->addWhere('name', '=', CRM_Herald_Upgrader_PreferencesProfile::NAME)
      ->execute();

    $gid = CRM_Herald_Upgrader_PreferencesProfile::ensure();

    self::assertSame($gid, $this->gid());
    self::assertCount(6, $this->fields($gid));
  }

}
