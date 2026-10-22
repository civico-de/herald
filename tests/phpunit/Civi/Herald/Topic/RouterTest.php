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
    $params = Router::resolveFrom(['default' => ['channel' => 'ops_bot', 'chat_id' => '-1']], 'anything');
    $this->assertSame('-1', $params['chat_id']);
    $this->assertSame('ops_bot', $params['channel']);
  }

  public function testARouteWithoutAChannelThrows(): void {
    $this->expectExceptionMessage('herald: the route of topic "anything" names no channel.');
    Router::resolveFrom(['default' => ['chat_id' => '-1']], 'anything');
  }

  public function testAFanOutRouteNeedsNoChannel(): void {
    $params = Router::resolveFrom(['members' => ['delivery_mode' => 'subscribers']], 'members');
    $this->assertSame('subscribers', $params['delivery_mode']);
  }

  public function testARouteToSubscribersReachesThoseOfItsOwnTopic(): void {
    $map = [
      'default' => ['delivery_mode' => 'subscribers'],
      'news' => ['label' => 'News', 'subscribable' => TRUE],
      'digest' => ['subscribers_of' => 'news'],
    ];
    $this->assertSame(
      ['delivery_mode' => 'subscribers', 'subscribers_of' => 'news'],
      Router::resolveFrom($map, 'news'),
    );
    $this->assertSame('news', Router::resolveFrom($map, 'digest')['subscribers_of']);
  }

  public function testATopicContactsSubscribeToKeepsItsOwnSubscribersOverDefault(): void {
    $map = [
      'default' => ['delivery_mode' => 'subscribers', 'subscribers_of' => 'members'],
      'board' => ['subscribable' => TRUE],
      'digest' => [],
      'news' => ['subscribable' => TRUE, 'subscribers_of' => 'members'],
    ];
    $this->assertSame('board', Router::resolveFrom($map, 'board')['subscribers_of']);
    $this->assertSame('members', Router::resolveFrom($map, 'digest')['subscribers_of']);
    $this->assertSame('members', Router::resolveFrom($map, 'news')['subscribers_of']);
  }

  public function testTheKeysOfATopicNeverReachTheDelivery(): void {
    $params = Router::resolveFrom([
      'default' => ['channel' => 'ops_bot', 'label' => 'Fallback', 'subscribable' => TRUE],
      'ops' => ['chat_id' => '-1', 'label' => 'Ops', 'subscribable' => TRUE],
    ], 'ops');
    $this->assertSame(['channel' => 'ops_bot', 'chat_id' => '-1'], $params);
  }

  public function testUnroutedTopicThrows(): void {
    $this->expectException(\CRM_Core_Exception::class);
    Router::resolveFrom(['ops' => ['chat_id' => '-1']], 'mail');
  }

}
