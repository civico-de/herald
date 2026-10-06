<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Api4\OptionValue;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Option names resolve to this installation's value; a missing or inactive
 * option is an error, never a guessed value.
 *
 * @group headless
 */
class ActivityOptionResolverTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function testResolvesTheInstallationValue(): void {
    // A value no seeded option has, so a hard-coded mapping cannot pass.
    OptionValue::create(checkPermissions: FALSE)
      ->addValue('option_group_id:name', 'activity_status')
      ->addValue('name', 'herald_test_status')
      ->addValue('label', 'Herald test status')
      ->addValue('value', 977)
      ->execute();

    $this->assertSame(977, ActivityOptionResolver::resolve('activity_status', 'herald_test_status'));
  }

  public function testUnknownOptionFails(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('"No such status" does not exist in option group "activity_status"');
    ActivityOptionResolver::resolve('activity_status', 'No such status');
  }

  public function testInactiveOptionFails(): void {
    OptionValue::update(checkPermissions: FALSE)
      ->addWhere('option_group_id:name', '=', 'activity_status')
      ->addWhere('name', '=', 'Cancelled')
      ->addValue('is_active', value: FALSE)
      ->execute();
    $this->expectException(\CRM_Core_Exception::class);
    ActivityOptionResolver::resolve('activity_status', 'Cancelled');
  }

}
