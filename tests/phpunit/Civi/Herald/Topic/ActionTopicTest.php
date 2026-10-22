<?php

declare(strict_types = 1);

namespace Civi\Herald\Topic;

use Civi\Herald\Dispatch\Dispatcher;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A rule action or job that names a topic: the route brings the delivery,
 * the action only what it sends.
 *
 * @group headless
 */
class ActionTopicTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \Civi\Herald\ChannelFixture::add('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))]);
    \Civi::settings()->set(Router::SETTING, ['ops' => ['channel' => 'ops_bot', 'chat_id' => '-100']]);
  }

  public function testTheRouteReplacesTheDeliveryOfTheAction(): void {
    $this->assertSame(
      ['template' => 't', 'vars' => ['a' => 1], 'channel' => 'ops_bot', 'chat_id' => '-100'],
      Dispatcher::withTopic(['topic' => 'ops', 'template' => 't', 'vars' => ['a' => 1], 'chat_id' => '-1']),
    );
    $own = ['channel' => 'ops_bot', 'chat_id' => '-1'];
    $this->assertSame($own, Dispatcher::withTopic($own));
  }

  public function testATopicSendTakesNoDeliveryOfItsOwn(): void {
    $validator = new RouteValidator();
    $this->assertSame(
      [],
      $validator->actionErrors(['topic' => 'ops', 'template' => 't', 'digest' => 'd', 'chat_id' => '']),
    );
    $this->assertSame(
      ['A send through a topic takes its delivery from the topic; remove chat_id, aggregate_minutes.'],
      $validator->actionErrors(['topic' => 'ops', 'chat_id' => '-1', 'aggregate_minutes' => 5]),
    );
    $this->assertSame(
      ['There is no topic "nowhere" and no "default" route.'],
      $validator->actionErrors(['topic' => 'nowhere']),
    );
  }

  public function testADeliveryOfItsOwnNeedsAChannel(): void {
    $validator = new RouteValidator();
    $this->assertSame(['Choose a channel.'], $validator->actionErrors(['chat_id' => '-1']));
    $this->assertSame(
      ['Choose the topic whose subscribers to reach.'],
      $validator->actionErrors(['delivery_mode' => 'subscribers']),
    );
    $this->assertSame(
      ['Contacts cannot subscribe to the topic "ops".'],
      $validator->actionErrors(['delivery_mode' => 'subscribers', 'subscribers_of' => 'ops']),
    );
    \Civi::settings()->set(Router::SETTING, ['ops' => ['channel' => 'ops_bot', 'subscribable' => TRUE]]);
    $this->assertSame([], $validator->actionErrors(['delivery_mode' => 'subscribers', 'subscribers_of' => 'ops']));
    $this->assertSame(
      ['An address list goes through an email channel; "ops_bot" is not one.'],
      $validator->actionErrors([
        'delivery_mode' => 'addresses',
        'channel' => 'ops_bot',
        'email_addresses' => 'a@example.org',
      ]),
    );
  }

}
