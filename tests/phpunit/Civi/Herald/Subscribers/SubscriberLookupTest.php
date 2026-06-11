<?php

namespace Civi\Herald\Subscribers;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for SubscriberLookup::mapRows / buildConfigForChannel.
 * @group unit
 */
class SubscriberLookupTest extends TestCase {

  private function row(array $overrides): array {
    return $overrides + [
      'id' => 0,
      'herald_preferences.preferred_channel' => '',
      'herald_preferences.telegram_chat_id' => '',
      'herald_preferences.pushover_user_key' => '',
      'herald_preferences.ntfy_topic' => '',
      'herald_preferences.webhook_url' => '',
      'herald_preferences.subscriptions' => [],
      'email_primary.email' => '',
      'do_not_email' => FALSE,
    ];
  }

  public function testTelegramSubscriberMapped(): void {
    $rows = [$this->row([
      'id' => 42,
      'herald_preferences.preferred_channel' => 'telegram',
      'herald_preferences.telegram_chat_id' => '111',
    ])];
    $this->assertSame([
      ['contact_id' => 42, 'channel' => 'telegram', 'config' => ['chat_id' => '111']],
    ], SubscriberLookup::mapRows($rows));
  }

  public function testNtfySubscriberMapped(): void {
    $rows = [$this->row([
      'id' => 7,
      'herald_preferences.preferred_channel' => 'ntfy',
      'herald_preferences.ntfy_topic' => 'mytopic',
    ])];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertSame('ntfy', $mapped[0]['channel']);
    $this->assertSame(['ntfy_topic' => 'mytopic'], $mapped[0]['config']);
  }

  public function testPushoverSubscriberMapped(): void {
    $rows = [$this->row([
      'id' => 9,
      'herald_preferences.preferred_channel' => 'pushover',
      'herald_preferences.pushover_user_key' => 'uvw',
    ])];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertSame(['pushover_user_key' => 'uvw'], $mapped[0]['config']);
  }

  public function testWebhookSubscriberMapped(): void {
    $rows = [$this->row([
      'id' => 11,
      'herald_preferences.preferred_channel' => 'webhook',
      'herald_preferences.webhook_url' => 'https://example.com/x',
    ])];
    $this->assertSame(['webhook_url' => 'https://example.com/x'], SubscriberLookup::mapRows($rows)[0]['config']);
  }

  public function testEmailSubscriberMappedToPrimaryAddress(): void {
    $rows = [$this->row([
      'id' => 13,
      'herald_preferences.preferred_channel' => 'email',
      'email_primary.email' => 'ada@example.org',
    ])];
    $this->assertSame([
      ['contact_id' => 13, 'channel' => 'email', 'config' => ['email_to' => 'ada@example.org']],
    ], SubscriberLookup::mapRows($rows));
  }

  public function testEmailSubscriberWithDoNotEmailSkipped(): void {
    $rows = [$this->row([
      'id' => 13,
      'herald_preferences.preferred_channel' => 'email',
      'email_primary.email' => 'ada@example.org',
      'do_not_email' => TRUE,
    ])];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testEmailSubscriberWithoutAddressSkipped(): void {
    $rows = [$this->row([
      'id' => 13,
      'herald_preferences.preferred_channel' => 'email',
    ])];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testContactWithoutChannelSkipped(): void {
    $rows = [
      $this->row(['id' => 1]),                              // no preferred_channel
      $this->row(['id' => 2, 'herald_preferences.preferred_channel' => 'telegram', 'herald_preferences.telegram_chat_id' => '999']),
    ];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertCount(1, $mapped);
    $this->assertSame(2, $mapped[0]['contact_id']);
  }

  public function testContactWithChannelButNoRecipientSkipped(): void {
    $rows = [$this->row([
      'id' => 1,
      'herald_preferences.preferred_channel' => 'telegram',
      // no telegram_chat_id
    ])];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testUnknownChannelSkipped(): void {
    $rows = [$this->row([
      'id' => 1,
      'herald_preferences.preferred_channel' => 'matrix',
    ])];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testMixedSubscribersAcrossChannels(): void {
    $rows = [
      $this->row(['id' => 1, 'herald_preferences.preferred_channel' => 'telegram', 'herald_preferences.telegram_chat_id' => 'A']),
      $this->row(['id' => 2, 'herald_preferences.preferred_channel' => 'ntfy', 'herald_preferences.ntfy_topic' => 'B']),
      $this->row(['id' => 3, 'herald_preferences.preferred_channel' => 'pushover']),  // missing recipient → skip
    ];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertCount(2, $mapped);
    $this->assertSame([1, 2], array_column($mapped, 'contact_id'));
  }

}
