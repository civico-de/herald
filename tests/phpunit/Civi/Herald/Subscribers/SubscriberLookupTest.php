<?php

namespace Civi\Herald\Subscribers;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for SubscriberLookup::mapRows / buildConfigForChannel.
 * @group unit
 * @mago-expect lint:too-many-methods
 */
class SubscriberLookupTest extends TestCase {

  private function row(array $overrides): array {
    return $overrides
    + [
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
    $row = $this->row([
      'id' => 42,
      'herald_preferences.preferred_channel' => 'telegram',
      'herald_preferences.telegram_chat_id' => '111',
    ]);
    $rows = [$row];
    $this->assertSame(
      [
        ['contact_id' => 42, 'channel' => 'telegram', 'config' => ['chat_id' => '111']],
      ],
      SubscriberLookup::mapRows($rows),
    );
  }

  public function testNtfySubscriberMapped(): void {
    $row = $this->row([
      'id' => 7,
      'herald_preferences.preferred_channel' => 'ntfy',
      'herald_preferences.ntfy_topic' => 'mytopic',
    ]);
    $rows = [$row];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertSame('ntfy', $mapped[0]['channel']);
    $this->assertSame(['ntfy_topic' => 'mytopic'], $mapped[0]['config']);
  }

  public function testPushoverSubscriberMapped(): void {
    $row = $this->row([
      'id' => 9,
      'herald_preferences.preferred_channel' => 'pushover',
      'herald_preferences.pushover_user_key' => 'uvw',
    ]);
    $rows = [$row];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertSame(['pushover_user_key' => 'uvw'], $mapped[0]['config']);
  }

  public function testWebhookSubscriberMapped(): void {
    $row = $this->row([
      'id' => 11,
      'herald_preferences.preferred_channel' => 'webhook',
      'herald_preferences.webhook_url' => 'https://example.com/x',
    ]);
    $rows = [$row];
    $this->assertSame(['webhook_url' => 'https://example.com/x'], SubscriberLookup::mapRows($rows)[0]['config']);
  }

  public function testEmailSubscriberMappedToPrimaryAddress(): void {
    $row = $this->row([
      'id' => 13,
      'herald_preferences.preferred_channel' => 'email',
      'email_primary.email' => 'ada@example.org',
    ]);
    $rows = [$row];
    $this->assertSame(
      [
        ['contact_id' => 13, 'channel' => 'email', 'config' => ['email_to' => 'ada@example.org']],
      ],
      SubscriberLookup::mapRows($rows),
    );
  }

  public function testEmailSubscriberWithDoNotEmailSkipped(): void {
    $row = $this->row([
      'id' => 13,
      'herald_preferences.preferred_channel' => 'email',
      'email_primary.email' => 'ada@example.org',
      'do_not_email' => TRUE,
    ]);
    $rows = [$row];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testEmailSubscriberWithoutAddressSkipped(): void {
    $row = $this->row([
      'id' => 13,
      'herald_preferences.preferred_channel' => 'email',
    ]);
    $rows = [$row];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testContactWithoutChannelSkipped(): void {
    $rows = [
      // no preferred_channel
      $this->row(['id' => 1]),
      $this->row([
        'id' => 2,
        'herald_preferences.preferred_channel' => 'telegram',
        'herald_preferences.telegram_chat_id' => '999',
      ]),
    ];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertCount(1, $mapped);
    $this->assertSame(2, $mapped[0]['contact_id']);
  }

  public function testContactWithChannelButNoRecipientSkipped(): void {
    $row = $this->row([
      'id' => 1,
      'herald_preferences.preferred_channel' => 'telegram',
      // no telegram_chat_id
    ]);
    $rows = [$row];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testUnknownChannelSkipped(): void {
    $row = $this->row([
      'id' => 1,
      'herald_preferences.preferred_channel' => 'matrix',
    ]);
    $rows = [$row];
    $this->assertSame([], SubscriberLookup::mapRows($rows));
  }

  public function testMixedSubscribersAcrossChannels(): void {
    $rows = [
      $this->row([
        'id' => 1,
        'herald_preferences.preferred_channel' => 'telegram',
        'herald_preferences.telegram_chat_id' => 'A',
      ]),
      $this->row(['id' => 2, 'herald_preferences.preferred_channel' => 'ntfy', 'herald_preferences.ntfy_topic' => 'B']),
      // missing recipient → skip
      $this->row(['id' => 3, 'herald_preferences.preferred_channel' => 'pushover']),
    ];
    $mapped = SubscriberLookup::mapRows($rows);
    $this->assertCount(2, $mapped);
    $this->assertSame([1, 2], array_column($mapped, 'contact_id'));
  }

}
