<?php

namespace Civi\Herald\Api4\Action;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Herald.getChannels — makes the per-channel config contract discoverable to
 * callers instead of copyable out of herald's source.
 *
 * @group headless
 */
class GetChannelsTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function testListsEveryShippedChannel(): void {
    $keys = (array) civicrm_api4('Herald', 'getChannels', ['checkPermissions' => FALSE])->column('key');
    sort($keys);
    $this->assertSame(['email', 'ntfy', 'pushover', 'telegram', 'webhook'], $keys);
  }

  public function testExposesTheConfigSchemaOfAChannel(): void {
    $row = civicrm_api4('Herald', 'getChannels', [
      'checkPermissions' => FALSE,
      'channel' => 'email',
    ])->single();

    $this->assertSame('email', $row['key']);
    $this->assertArrayHasKey('email_to', $row['config_schema']);
    $this->assertArrayHasKey('email_subject', $row['config_schema']);
    $this->assertTrue($row['config_schema']['email_to']['required']);
  }

  public function testExposesTheSemanticFieldMapping(): void {
    $rows = civicrm_api4('Herald', 'getChannels', ['checkPermissions' => FALSE])->indexBy('key');

    $this->assertSame(['subject' => 'email_subject', 'format' => 'email_format'], $rows['email']['semantic_fields']);
    $this->assertSame(['subject' => 'ntfy_title'], $rows['ntfy']['semantic_fields']);
    $this->assertSame([], $rows['telegram']['semantic_fields']);
  }

  /**
   * Every declared semantic must point at a key the channel actually accepts —
   * otherwise Herald.send would write config the channel silently ignores.
   */
  public function testEverySemanticFieldExistsInTheConfigSchema(): void {
    foreach (civicrm_api4('Herald', 'getChannels', ['checkPermissions' => FALSE]) as $row) {
      foreach ($row['semantic_fields'] as $semantic => $key) {
        $this->assertArrayHasKey(
          $key,
          $row['config_schema'],
          sprintf('channel "%s" maps semantic "%s" to unknown config key "%s"', $row['key'], $semantic, $key),
        );
      }
    }
  }

  public function testUnknownChannelThrows(): void {
    $this->expectException(\CRM_Core_Exception::class);
    civicrm_api4('Herald', 'getChannels', ['checkPermissions' => FALSE, 'channel' => 'nope']);
  }

}
