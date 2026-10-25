<?php

declare(strict_types = 1);

use Civi\Api4\UFField;
use Civi\Api4\UFGroup;
use Civi\Api4\UFJoin;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The self-service profile of earlier releases goes with its fields and its
 * standalone-form use; other profiles stay.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_PreferencesProfileTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  private static function profile(string $name): int {
    /** @var array{id: int} $group */
    $group = UFGroup::create(checkPermissions: FALSE)
      ->addValue('name', $name)
      ->addValue('title', $name)
      ->addValue('group_type', 'Individual,Contact')
      ->addChain('field', UFField::create(checkPermissions: FALSE)
        ->addValue('uf_group_id', '$id')
        ->addValue('field_name', 'first_name')
        ->addValue('label', 'First name'))
      ->addChain('join', UFJoin::create(checkPermissions: FALSE)
        ->addValue('uf_group_id', '$id')
        ->addValue('module', 'Profile')
        ->addValue('weight', 1))
      ->execute()
      ->single();
    return $group['id'];
  }

  /**
   * @return array{groups: int, fields: int, joins: int}
   */
  private static function left(int $gid): array {
    return [
      'groups' => UFGroup::get(checkPermissions: FALSE)->addWhere('id', '=', $gid)->execute()->count(),
      'fields' => UFField::get(checkPermissions: FALSE)->addWhere('uf_group_id', '=', $gid)->execute()->count(),
      'joins' => UFJoin::get(checkPermissions: FALSE)->addWhere('uf_group_id', '=', $gid)->execute()->count(),
    ];
  }

  public function testTheProfileGoesWithItsFieldsAndJoins(): void {
    $herald = self::profile(CRM_Herald_Upgrader_PreferencesProfile::NAME);
    $other = self::profile('someone_elses_profile');

    CRM_Herald_Upgrader_PreferencesProfile::remove();

    self::assertSame(['groups' => 0, 'fields' => 0, 'joins' => 0], self::left($herald));
    self::assertSame(['groups' => 1, 'fields' => 1, 'joins' => 1], self::left($other));
  }

  public function testInstallShipsNoProfile(): void {
    self::assertSame(
      0,
      UFGroup::get(checkPermissions: FALSE)
        ->addWhere('name', '=', CRM_Herald_Upgrader_PreferencesProfile::NAME)
        ->execute()
        ->count(),
    );
  }

}
