<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * Aggregation group keys of the dispatcher.
 * @group unit
 */
class DispatcherTest extends TestCase {

  /**
   * Why the group key must be taken before the rendered subject is applied:
   * with the subject in the params every message forms its own group and the
   * aggregation window only ever adds latency.
   */
  public function testGroupHashChangesOnceARenderedSubjectIsMixedIn(): void {
    $raw = ['channel' => 'email', 'template' => 'alert', 'aggregate_minutes' => 15];
    $this->assertNotSame(
      Dispatcher::groupHash($raw),
      Dispatcher::groupHash(ChannelConfig::withSubject($raw, '✅ Donation #5')),
    );
  }

  public function testGroupHashIsStableAndNamespacedByPrefix(): void {
    $params = ['channel' => 'telegram', 'chat_id' => '-100'];
    $this->assertSame(Dispatcher::groupHash($params), Dispatcher::groupHash($params));
    $this->assertNotSame(Dispatcher::groupHash($params), Dispatcher::groupHash($params, 'topic:ops:'));
  }

  /**
   * An unencodable params bag must fail loudly: an empty replacement string
   * collapses every affected configuration onto one hash and mixes unrelated
   * alerts into a single message.
   */
  public function testGroupHashRefusesUnencodableParams(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/aggregation group key/');
    Dispatcher::groupHash(['chat_id' => '-100', 'retry_after' => INF]);
  }

}
