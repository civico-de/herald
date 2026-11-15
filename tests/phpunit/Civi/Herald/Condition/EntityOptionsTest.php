<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The entities the field-transition condition offers.
 *
 * @group headless
 */
class EntityOptionsTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testOffersTheEntitiesStoredInTablesByTitle(): void {
    $options = EntityOptions::forCondition();
    $this->assertSame('Membership', $options['Membership'] ?? NULL);
    $this->assertArrayHasKey('Contribution', $options);
    $this->assertArrayNotHasKey('Setting', $options, 'a settings value fires no entity hook');
  }

  public function testKeepsAStoredUnknownEntitySelectable(): void {
    $this->assertSame('Membershipp (unknown entity)', EntityOptions::forCondition('Membershipp')['Membershipp']);
    $this->assertSame('Membership', EntityOptions::forCondition('Membership')['Membership']);
  }

}
