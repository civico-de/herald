<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Topic\Router;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A topic matches the subscriptions to its exact name.
 *
 * @group headless
 */
class SubscribableTopicsTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testWildcardCharactersInANameMatchOnlyThemselves(): void {
    \Civi::settings()->set(Router::SETTING, [
      'ops-de' => ['subscribable' => TRUE],
      'ops_de' => ['subscribable' => TRUE],
      'ops%' => ['subscribable' => TRUE],
    ]);
    \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Subscriber')
      ->addChain('subscription', \Civi\Api4\CustomValue::create(Subscriptions::GROUP, checkPermissions: FALSE)
        ->setValues(['entity_id' => '$id', 'channel' => 'email', 'topics' => ['ops-de']]))
      ->addChain('email', \Civi\Api4\Email::create(checkPermissions: FALSE)
        ->addValue('contact_id', '$id')
        ->addValue('email', 'subscriber@example.org'))
      ->execute();

    $this->assertSame(1, Subscriptions::subscribers('ops-de'));
    $this->assertSame(0, Subscriptions::subscribers('ops_de'));
    $this->assertSame(0, Subscriptions::subscribers('ops%'));
    $this->assertSame([], (new SubscriberLookup())->findByTopic('ops_de'));
  }

}
