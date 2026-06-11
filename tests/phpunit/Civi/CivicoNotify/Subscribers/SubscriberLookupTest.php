<?php

namespace Civi\CivicoNotify\Subscribers;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for SubscriberLookup::mapRows / buildConfigForChannel.
 * @group unit
 */
class SubscriberLookupTest extends TestCase {

  private function row(array $overrides): array {
    return $overrides + [
      'id' => 0,
      'civico_notify_preferences.preferred_channel' => '',
      'civico_notify_preferences.telegram_chat_id' => '',
      'civico_notify_preferences.pushover_user_key' => '',
      'civico_notify_preferences.ntfy_topic' => '',
      'civico_notify_preferences.webhook_url' => '',
      'civico_notify_preferences.subscriptions' => [],
    ];
  }

  public function testTelegramSubscriberMapped(): void {
    $rows = [$this->row([
      'id' => 42,
      'civico_notify_preferences.preferred_channel' => 'telegram',
      'civico_notify_preferences.telegram_chat_id' => '111',
    ])];
    $this->assertSame([
      ['contact_id' => 42, 'channel' => 'telegram', 'config' => ['chat_id' => '111']],
    ], SubscriberLookup::mapRows($rows));
  }

  public function testNtfySubscriberMapped(): void {
    $rows = [$this->row([
      'id' => 7,
      'civico_notify_preferences.preferred_channel' => 'ntfy',
      'civico_notify_preferences.ntfy_topic' => 'mytopic',
    ])];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertSame('ntfy', $mapped[0]['channel']);
    $this->assertSame(['ntfy_topic' => 'mytopic'], $mapped[0]['config']);
  }

  public function testPushoverSubscriberMapped(): void {
    $rows = [$this->row([
      'id' => 9,
      'civico_notify_preferences.preferred_channel' => 'pushover',
      'civico_notify_preferences.pushover_user_key' => 'uvw',
    ])];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertSame(['pushover_user_key' => 'uvw'], $mapped[0]['config']);
  }

  public function testWebhookSubscriberMapped(): void {
    $rows = [$this->row([
      'id' => 11,
      'civico_notify_preferences.preferred_channel' => 'webhook',
      'civico_notify_preferences.webhook_url' => 'https://example.com/x',
    ])];
    $this->assertSame(['webhook_url' => 'https://example.com/x'], SubscriberLookup::mapRows($rows)[0]['config']);
  }

  public function testContactWithoutChannelSkipped(): void {
    $rows = [
      $this->row(['id' => 1]),                              // no preferred_channel
      $this->row(['id' => 2, 'civico_notify_preferences.preferred_channel' => 'telegram', 'civico_notify_preferences.telegram_chat_id' => '999']),
    ];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertCount(1, $mapped);
    $this->assertSame(2, $mapped[0]['contact_id']);
  }

  public function testContactWithChannelButNoRecipientSkipped(): void {
    $rows = [$this->row([
      'id' => 1,
      'civico_notify_preferences.preferred_channel' => 'telegram',
      // no telegram_chat_id
    ])];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testUnknownChannelSkipped(): void {
    $rows = [$this->row([
      'id' => 1,
      'civico_notify_preferences.preferred_channel' => 'matrix',
    ])];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testMixedSubscribersAcrossChannels(): void {
    $rows = [
      $this->row(['id' => 1, 'civico_notify_preferences.preferred_channel' => 'telegram', 'civico_notify_preferences.telegram_chat_id' => 'A']),
      $this->row(['id' => 2, 'civico_notify_preferences.preferred_channel' => 'ntfy', 'civico_notify_preferences.ntfy_topic' => 'B']),
      $this->row(['id' => 3, 'civico_notify_preferences.preferred_channel' => 'pushover']),  // missing recipient → skip
    ];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertCount(2, $mapped);
    $this->assertSame([1, 2], array_column($mapped, 'contact_id'));
  }

}
