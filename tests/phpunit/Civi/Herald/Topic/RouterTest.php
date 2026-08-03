<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests — no Civi headless needed.
 * @group unit
 */
class RouterTest extends TestCase {

  public function testTopicEntryMergedOverDefault(): void {
    $params = Router::resolveFrom([
      'default' => ['channel' => 'telegram', 'parse_mode' => 'HTML'],
      'ops' => ['chat_id' => '-100', 'aggregate_minutes' => 0],
    ], 'ops');
    $this->assertSame('telegram', $params['channel']);
    $this->assertSame('HTML', $params['parse_mode']);
    $this->assertSame('-100', $params['chat_id']);
  }

  public function testUnknownTopicFallsBackToDefault(): void {
    $params = Router::resolveFrom(['default' => ['chat_id' => '-1']], 'anything');
    $this->assertSame('-1', $params['chat_id']);
    $this->assertSame('telegram', $params['channel']);
  }

  public function testUnroutedTopicThrows(): void {
    $this->expectException(\CRM_Core_Exception::class);
    Router::resolveFrom(['ops' => ['chat_id' => '-1']], 'mail');
  }

}
