<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * Fan-out options and mode checks of the delivery.
 * @group unit
 */
class DeliveryTest extends TestCase {

  public function testOptionsDefaultsKeepCurrentBehavior(): void {
    $this->assertSame(
      ['respect_suspension' => FALSE, 'location_type_id' => 0],
      Delivery::options([]),
    );
  }

  public function testOptionsPicksUpSuspensionLocationAndScope(): void {
    $options = Delivery::options([
      'respect_suspension' => TRUE,
      'email_location_type' => '3',
      'scope_entity_type' => 'Event',
      'scope_entity_id' => 5,
    ]);
    $this->assertTrue($options['respect_suspension']);
    $this->assertSame(3, $options['location_type_id']);
    $this->assertSame(['entity_type' => 'Event', 'entity_id' => 5], $options['scope']);
  }

  public function testOptionsIgnoresIncompleteScope(): void {
    $this->assertArrayNotHasKey(
      'scope',
      Delivery::options(['scope_entity_type' => 'Event']),
    );
  }

  /**
   * Sent directly instead, a misspelt fan-out would reach one recipient only.
   */
  public function testAnUnknownModeFails(): void {
    $this->expectExceptionMessage('herald: unknown delivery_mode "subscriber"');
    Delivery::send('body', ['delivery_mode' => 'subscriber']);
  }

  public function testGroupModeWithoutGroupIdThrows(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/group_id/');
    Delivery::send('body', ['delivery_mode' => 'group']);
  }

}
