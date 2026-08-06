<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for SubscriberLookup::mapRows / buildConfigForChannel.
 * @group unit
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

  public function testSuspensionSkippedAndCountedWhenOptionSet(): void {
    $rows = [
      $this->row([
        'id' => 20,
        'herald_preferences.preferred_channel' => 'email',
        'email_primary.email' => 'opted-out@example.org',
        'is_opt_out' => TRUE,
      ]),
      $this->row([
        'id' => 21,
        'herald_preferences.preferred_channel' => 'email',
        'email_primary.email' => 'on-hold@example.org',
        'email_primary.on_hold' => 1,
      ]),
      $this->row([
        'id' => 22,
        'herald_preferences.preferred_channel' => 'email',
        'email_primary.email' => 'fine@example.org',
      ]),
    ];
    $mapped = SubscriberLookup::mapRowsDetailed($rows, ['respect_suspension' => TRUE]);
    $this->assertSame([22], array_column($mapped['recipients'], 'contact_id'));
    $this->assertSame(['suspended' => 2], $mapped['skipped']);
  }

  public function testOptOutStillDeliveredWithoutRespectSuspension(): void {
    $rows = [
      $this->row([
        'id' => 20,
        'herald_preferences.preferred_channel' => 'email',
        'email_primary.email' => 'opted-out@example.org',
        'is_opt_out' => TRUE,
      ]),
    ];
    $this->assertCount(1, SubscriberLookup::mapRows($rows));
  }

  public function testSuspensionOnlyAppliesToEmailChannel(): void {
    $rows = [
      $this->row([
        'id' => 23,
        'herald_preferences.preferred_channel' => 'telegram',
        'herald_preferences.telegram_chat_id' => '5',
        'is_opt_out' => TRUE,
      ]),
    ];
    $this->assertCount(1, SubscriberLookup::mapRows($rows, ['respect_suspension' => TRUE]));
  }

  public function testLocationTypeAddressPreferredWithPrimaryFallback(): void {
    $withLoc = $this->row([
      'id' => 24,
      'herald_preferences.preferred_channel' => 'email',
      'email_primary.email' => 'primary@example.org',
      'herald_loc_email.email' => 'work@example.org',
    ]);
    $withoutLoc = $this->row([
      'id' => 25,
      'herald_preferences.preferred_channel' => 'email',
      'email_primary.email' => 'primary2@example.org',
    ]);
    $mapped = SubscriberLookup::mapRows([$withLoc, $withoutLoc], ['location_type_id' => 3]);
    $this->assertSame('work@example.org', $mapped[0]['config']['email_to']);
    $this->assertSame('primary2@example.org', $mapped[1]['config']['email_to']);
  }

  public function testScopedSubscriptionOnlyMatchesItsEntity(): void {
    $scoped = $this->row([
      'id' => 30,
      'herald_preferences.preferred_channel' => 'telegram',
      'herald_preferences.telegram_chat_id' => 'A',
      'herald_preferences.source_entity_type' => 'Event',
      'herald_preferences.source_entity_id' => 5,
    ]);
    $global = $this->row([
      'id' => 31,
      'herald_preferences.preferred_channel' => 'telegram',
      'herald_preferences.telegram_chat_id' => 'B',
    ]);

    // Matching scope: both reached.
    $match = SubscriberLookup::mapRowsDetailed(
      [$scoped, $global],
      ['scope' => ['entity_type' => 'Event', 'entity_id' => 5]],
    );
    $this->assertSame([30, 31], array_column($match['recipients'], 'contact_id'));

    // Different instance: only the global subscription.
    $other = SubscriberLookup::mapRowsDetailed(
      [$scoped, $global],
      ['scope' => ['entity_type' => 'Event', 'entity_id' => 6]],
    );
    $this->assertSame([31], array_column($other['recipients'], 'contact_id'));
    $this->assertSame(['scope_mismatch' => 1], $other['skipped']);

    // Unscoped notification: scoped subscriptions stay silent.
    $unscoped = SubscriberLookup::mapRowsDetailed([$scoped, $global], []);
    $this->assertSame([31], array_column($unscoped['recipients'], 'contact_id'));
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
