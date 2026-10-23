<?php

declare(strict_types = 1);

use Civi\Api4\CustomField;
use Civi\Api4\UFField;
use Civi\Api4\UFGroup;
use Civi\Api4\UFJoin;
use Civi\Herald\Subscribers\Subscriptions;
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
    return \Civi\Herald\HeadlessSite::standard()->apply();
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

  public function testInstallCreatesTheProfileOnTheGroupChannelAndTheRows(): void {
    $fields = $this->fields($this->gid());

    self::assertCount(5, $fields);
    self::assertSame('Channel', $fields[self::fieldName(Subscriptions::GROUP, 'channel')]);
    self::assertSame(
      'Channel for messages to my groups',
      $fields[self::fieldName(Subscriptions::GROUP_DELIVERY, 'channel')],
    );
    self::assertSame(
      'Leave it empty to receive messages to your groups by email.',
      UFField::get(checkPermissions: FALSE)
        ->addWhere('field_name', '=', self::fieldName(Subscriptions::GROUP_DELIVERY, 'channel'))
        ->execute()
        ->single()['help_pre'],
    );
    self::assertSame(['Profile'], $this->modules($this->gid()));
    $summary = UFField::get(checkPermissions: FALSE)
      ->addWhere('uf_group_id', '=', $this->gid())
      ->execute()
      ->column('is_multi_summary', 'field_name');
    self::assertSame(
      [
        self::fieldName(Subscriptions::GROUP_DELIVERY, 'channel') => FALSE,
        self::fieldName(Subscriptions::GROUP_DELIVERY, 'recipient') => FALSE,
        self::fieldName(Subscriptions::GROUP, 'channel') => TRUE,
        self::fieldName(Subscriptions::GROUP, 'recipient') => TRUE,
        self::fieldName(Subscriptions::GROUP, 'topics') => TRUE,
      ],
      array_map(boolval(...), $summary),
      'the row list shows the row fields, the form the group channel',
    );
  }

  private static function fieldName(string $group, string $name): string {
    /** @var array{id: int} $field */
    $field = CustomField::get(checkPermissions: FALSE)
      ->addSelect('id')
      ->addWhere('custom_group_id:name', '=', $group)
      ->addWhere('name', '=', $name)
      ->execute()
      ->single();
    return 'custom_' . $field['id'];
  }

  public function testUpgradeLetsTheProfileOpenAsAStandaloneForm(): void {
    $gid = $this->gid();
    UFJoin::delete(checkPermissions: FALSE)->addWhere('uf_group_id', '=', $gid)->execute();
    $ctx = new CRM_Queue_TaskContext();
    $ctx->log = CRM_Core_Error::createDebugLogger();

    self::assertTrue(CRM_Herald_Upgrader::_queueAdapter($ctx, 'herald', 'upgrade_1017'));
    self::assertTrue(CRM_Herald_Upgrader::_queueAdapter($ctx, 'herald', 'upgrade_1017'));

    self::assertSame(['Profile'], $this->modules($gid));
  }

  /**
   * @return list<string>
   */
  private function modules(int $gid): array {
    /** @var list<string> */
    return UFJoin::get(checkPermissions: FALSE)
      ->addWhere('uf_group_id', '=', $gid)
      ->execute()
      ->column('module');
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

    self::assertTrue(CRM_Herald_Upgrader::_queueAdapter($ctx, 'herald', 'upgrade_1017'));

    self::assertSame($gid, $this->gid());
    $fields = $this->fields($gid);
    self::assertCount(5, $fields);
    self::assertSame('Edited by an admin', $fields[$fieldNames[1]]);
  }

  public function testTheOldTitleIsReplacedAndAnEditedOneKept(): void {
    $gid = $this->gid();
    $title = static fn (): mixed => UFGroup::get(checkPermissions: FALSE)
      ->addWhere('id', '=', $gid)
      ->execute()
      ->single()['title'];
    $set = static fn (string $text) => UFGroup::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $gid)
      ->addValue('title', $text)
      ->execute();

    $set('My notification preferences');
    CRM_Herald_Upgrader_PreferencesProfile::ensure();
    self::assertSame('My notifications', $title());

    $set('Our alerts');
    CRM_Herald_Upgrader_PreferencesProfile::ensure();
    self::assertSame('Our alerts', $title());
  }

  public function testTheOldHelpTextIsReplacedAndAnEditedOneKept(): void {
    $gid = $this->gid();
    $help = static fn (): mixed => UFGroup::get(checkPermissions: FALSE)
      ->addWhere('id', '=', $gid)
      ->execute()
      ->single()['help_pre'];
    $set = static fn (string $text) => UFGroup::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $gid)
      ->addValue('help_pre', $text)
      ->execute();

    $set('Pick your preferred channel and what kinds of notifications you want to receive.');
    CRM_Herald_Upgrader_PreferencesProfile::ensure();
    self::assertSame(
      'Choose how messages to your groups reach you, and add a row for each channel you want topic notifications on.',
      $help(),
    );

    $set('Our own words');
    CRM_Herald_Upgrader_PreferencesProfile::ensure();
    self::assertSame('Our own words', $help());
  }

  public function testUpgradeCreatesTheProfileWhereNoneExists(): void {
    UFGroup::delete(checkPermissions: FALSE)
      ->addWhere('name', '=', CRM_Herald_Upgrader_PreferencesProfile::NAME)
      ->execute();

    $gid = CRM_Herald_Upgrader_PreferencesProfile::ensure();

    self::assertSame($gid, $this->gid());
    self::assertCount(5, $this->fields($gid));
  }

}
