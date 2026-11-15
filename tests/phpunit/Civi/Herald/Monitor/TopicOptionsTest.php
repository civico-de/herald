<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Topic\Router;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The topic select of the check monitor on the settings page.
 *
 * @group headless
 */
class TopicOptionsTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function tearDown(): void {
    \Civi::settings()->revert(Router::SETTING);
    \Civi::settings()->revert(Monitor::TOPIC_SETTING);
    parent::tearDown();
  }

  public function testOffersEveryConfiguredTopicByItsLabel(): void {
    \Civi::settings()->set(Router::SETTING, [
      'ops' => ['channel' => 'mail', 'label' => 'Operations'],
      'donations' => ['channel' => 'mail'],
    ]);
    \Civi::settings()->set(Monitor::TOPIC_SETTING, 'ops');

    $this->assertSame(['ops' => 'Operations', 'donations' => 'donations'], TopicOptions::forMonitor());
  }

  public function testKeepsAStoredTopicWithoutAnEntrySelectable(): void {
    \Civi::settings()->set(Router::SETTING, ['donations' => ['channel' => 'mail']]);
    \Civi::settings()->set(Monitor::TOPIC_SETTING, 'ops');
    $this->assertSame('ops (no such topic)', TopicOptions::forMonitor()['ops']);

    \Civi::settings()->set(Router::SETTING, ['default' => ['channel' => 'mail']]);
    $this->assertSame(
      ['default' => 'default', 'ops' => 'ops (through the default topic)'],
      TopicOptions::forMonitor(),
    );
  }

  public function testTheSettingIsASelectOfTheseOptions(): void {
    $meta = \Civi\Core\SettingsMetadata::getMetadata(['name' => [Monitor::TOPIC_SETTING]])[Monitor::TOPIC_SETTING];
    $this->assertSame('select', $meta['html_type']);
    $this->assertSame(TopicOptions::class . '::forMonitor', $meta['pseudoconstant']['callback']);
  }

}
