<?php

namespace Civi\CivicoNotify\Channel;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for ChannelRegistry.
 * @group unit
 */
class ChannelRegistryTest extends TestCase {

  private function makeFakeChannel(string $key): ChannelInterface {
    return new class($key) implements ChannelInterface {
      public function __construct(private string $key) {}
      public function send(string $renderedBody, array $config): void {}
      public function getKey(): string { return $this->key; }
      public function getConfigSchema(): array { return []; }
    };
  }

  public function testRegisterAndGet(): void {
    $registry = new ChannelRegistry();
    $channel = $this->makeFakeChannel('slack');
    $registry->register($channel);
    $this->assertSame($channel, $registry->get('slack'));
  }

  public function testHasReturnsTrueForRegistered(): void {
    $registry = new ChannelRegistry();
    $registry->register($this->makeFakeChannel('discord'));
    $this->assertTrue($registry->has('discord'));
    $this->assertFalse($registry->has('email'));
  }

  public function testListKeysReturnsAllRegistered(): void {
    $registry = new ChannelRegistry();
    $registry->register($this->makeFakeChannel('slack'));
    $registry->register($this->makeFakeChannel('discord'));
    $keys = $registry->listKeys();
    sort($keys);
    $this->assertSame(['discord', 'slack'], $keys);
  }

  public function testRegisterOverwritesSameKey(): void {
    $registry = new ChannelRegistry();
    $first = $this->makeFakeChannel('slack');
    $second = $this->makeFakeChannel('slack');
    $registry->register($first);
    $registry->register($second);
    $this->assertSame($second, $registry->get('slack'));
    $this->assertCount(1, $registry->listKeys());
  }

  public function testGetUnknownKeyThrows(): void {
    $registry = new ChannelRegistry();
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/not registered/');
    $registry->get('nope');
  }

  public function testDefaultRegistersTelegram(): void {
    $default = ChannelRegistry::default();
    $this->assertTrue($default->has('telegram'));
    $this->assertInstanceOf(TelegramChannel::class, $default->get('telegram'));
  }

  public function testDefaultIsMemoized(): void {
    $this->assertSame(ChannelRegistry::default(), ChannelRegistry::default());
  }

}
