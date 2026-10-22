<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Subscribers\SubscriberLookup;
use Civi\Herald\Topic\Router;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Subscriber mode reaches the subscribers of the topic it names, if contacts may subscribe to it.
 *
 * @group headless
 */
class SubscriberDeliveryTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->set(Router::SETTING, ['news' => ['subscribable' => TRUE], 'ops' => []]);
  }

  public function testItAsksForTheSubscribersOfTheNamedTopic(): void {
    $lookup = $this->createMock(SubscriberLookup::class);
    $lookup
      ->expects($this->once())
      ->method('findByTopic')
      ->with('news', ['respect_suspension' => FALSE, 'location_type_id' => 0])
      ->willReturn([]);
    $this->expectException(NoRecipientsException::class);
    $this->expectExceptionMessage('no recipients resolved');
    Delivery::send('body', ['delivery_mode' => 'subscribers', 'subscribers_of' => 'news'], $lookup);
  }

  public function testItNeedsATopic(): void {
    $lookup = $this->createMock(SubscriberLookup::class);
    $lookup->expects($this->never())->method('findByTopic');
    $this->expectException(NoRecipientsException::class);
    $this->expectExceptionMessage('subscribers_of');
    Delivery::send('body', ['delivery_mode' => 'subscribers'], $lookup);
  }

  public function testATopicContactsCannotSubscribeToReachesNobody(): void {
    $lookup = $this->createMock(SubscriberLookup::class);
    $lookup->expects($this->never())->method('findByTopic');
    $this->expectException(NoRecipientsException::class);
    $this->expectExceptionMessage('herald: contacts cannot subscribe to the topic "ops".');
    Delivery::send('body', ['delivery_mode' => 'subscribers', 'subscribers_of' => 'ops'], $lookup);
  }

}
