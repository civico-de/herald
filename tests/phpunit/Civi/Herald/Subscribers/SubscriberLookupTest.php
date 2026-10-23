<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use PHPUnit\Framework\TestCase;

/**
 * How SubscriberLookup maps subscription rows and contact rows to recipients.
 * @group unit
 */
class SubscriberLookupTest extends TestCase {

  private const TYPES = [
    'telegram' => 'telegram',
    'ntfy' => 'ntfy',
    'pushover' => 'pushover',
    'webhook' => 'webhook',
    'email' => 'email',
  ];

  private static function map(array $subscriptions, array $contacts, array $options = []): array {
    return SubscriberLookup::mapRows($subscriptions, $contacts, $options, self::TYPES);
  }

  private static function detailed(array $subscriptions, array $contacts, array $options = []): array {
    return SubscriberLookup::mapRowsDetailed($subscriptions, $contacts, $options, self::TYPES);
  }

  private static function sub(
    int $contactId,
    string $channel,
    string $recipient = '',
    string $scopeType = '',
    int $scopeId = 0,
  ): array {
    return Subscriptions::row($contactId, $channel, $recipient, $scopeType, $scopeId);
  }

  private static function contact(int $id, array $overrides = []): array {
    return $overrides + ['id' => $id, 'email_primary.email' => '', 'do_not_email' => FALSE];
  }

  public function testTelegramSubscriberMapped(): void {
    $this->assertSame(
      [['contact_id' => 42, 'channel' => 'telegram', 'config' => ['chat_id' => '111']]],
      self::map([self::sub(42, 'telegram', '111')], [self::contact(42)]),
    );
  }

  public function testEachTypeFillsTheKeyItNamesForTheRecipient(): void {
    $mapped = self::map(
      [
        self::sub(7, 'ntfy', 'mytopic'),
        self::sub(9, 'pushover', 'uvw'),
        self::sub(11, 'webhook', 'https://example.com/x'),
      ],
      [self::contact(7), self::contact(9), self::contact(11)],
    );
    $this->assertSame(
      [['ntfy_topic' => 'mytopic'], ['pushover_user_key' => 'uvw'], ['webhook_url' => 'https://example.com/x']],
      array_column($mapped, 'config'),
    );
  }

  public function testEmailSubscriberMappedToPrimaryAddress(): void {
    $this->assertSame(
      [['contact_id' => 13, 'channel' => 'email', 'config' => ['email_to' => 'ada@example.org']]],
      self::map([self::sub(13, 'email')], [self::contact(13, ['email_primary.email' => 'ada@example.org'])]),
    );
  }

  public function testAnEnteredEmailAddressWinsOverThePrimaryOne(): void {
    $this->assertSame(
      [['contact_id' => 13, 'channel' => 'email', 'config' => ['email_to' => 'team@example.org']]],
      self::map([self::sub(13, 'email', 'team@example.org')], [self::contact(13, [
        'email_primary.email' => 'ada@example.org',
      ])]),
    );
  }

  public function testTheContactsEmailBlocksAlsoHoldForAnEnteredAddress(): void {
    $contacts = [
      self::contact(13, ['do_not_email' => TRUE]),
      self::contact(14, ['is_opt_out' => TRUE]),
    ];
    $subscriptions = [self::sub(13, 'email', 'team@example.org'), self::sub(14, 'email', 'team@example.org')];
    $this->assertSame(['suspended' => 2], self::detailed($subscriptions, $contacts, [
      'respect_suspension' => TRUE,
    ])['skipped']);
    $this->assertSame([14], array_column(self::map($subscriptions, $contacts), 'contact_id'));
  }

  public function testEmailSubscriberWithDoNotEmailSkipped(): void {
    $contact = self::contact(13, ['email_primary.email' => 'ada@example.org', 'do_not_email' => TRUE]);
    $this->assertSame([], self::map([self::sub(13, 'email')], [$contact]));
  }

  public function testEmailSubscriberWithoutAddressSkipped(): void {
    $this->assertSame([], self::map([self::sub(13, 'email')], [self::contact(13)]));
  }

  public function testContactWithChannelButNoRecipientSkipped(): void {
    $this->assertSame([], self::map([self::sub(1, 'telegram')], [self::contact(1)]));
  }

  public function testEveryLeftOutRowIsCountedByReason(): void {
    $subscriptions = [
      self::sub(1, ''),
      self::sub(2, 'telegram'),
      self::sub(3, 'matrix'),
      self::sub(4, 'email'),
      self::sub(5, 'email'),
    ];
    $contacts = [
      self::contact(1),
      self::contact(2),
      self::contact(3),
      self::contact(4),
      self::contact(5, ['email_primary.email' => 'a@example.org', 'do_not_email' => TRUE]),
    ];
    $this->assertSame(
      ['recipients' => [], 'skipped' => ['no_channel' => 2, 'no_recipient' => 2, 'suspended' => 1]],
      self::detailed($subscriptions, $contacts),
    );
  }

  public function testARowOfAContactTheQueryLeftOutReachesNobody(): void {
    $this->assertSame(['no_recipient' => 1], self::detailed([self::sub(8, 'telegram', '1')], [])['skipped']);
  }

  public function testChannelNameResolvesToItsType(): void {
    $this->assertSame(
      [['contact_id' => 5, 'channel' => 'ops_bot', 'config' => ['chat_id' => '77']]],
      SubscriberLookup::mapRows([self::sub(5, 'ops_bot', '77')], [self::contact(5)], [], ['ops_bot' => 'telegram']),
    );
  }

  public function testAContactWithRowsOnTwoChannelsGetsBoth(): void {
    $mapped = self::map(
      [self::sub(5, 'pushover', 'key'), self::sub(5, 'email')],
      [self::contact(5, ['email_primary.email' => 'ada@example.org'])],
    );
    $this->assertSame(['pushover', 'email'], array_column($mapped, 'channel'));
  }

  public function testTwoRowsReachingAContactTheSameWaySendOnce(): void {
    $mapped = self::map(
      [self::sub(5, 'telegram', '1', 'Event', 42), self::sub(5, 'telegram', '1'), self::sub(5, 'telegram', '2')],
      [self::contact(5)],
      ['scope' => ['entity_type' => 'Event', 'entity_id' => 42]],
    );
    $this->assertSame([['chat_id' => '1'], ['chat_id' => '2']], array_column($mapped, 'config'));
  }

  public function testSuspensionSkippedAndCountedWhenOptionSet(): void {
    $contacts = [
      self::contact(20, ['email_primary.email' => 'opted-out@example.org', 'is_opt_out' => TRUE]),
      self::contact(21, ['email_primary.email' => 'on-hold@example.org', 'email_primary.on_hold' => 1]),
      self::contact(22, ['email_primary.email' => 'fine@example.org']),
    ];
    $subscriptions = [self::sub(20, 'email'), self::sub(21, 'email'), self::sub(22, 'email')];
    $mapped = self::detailed($subscriptions, $contacts, ['respect_suspension' => TRUE]);
    $this->assertSame([22], array_column($mapped['recipients'], 'contact_id'));
    $this->assertSame(['suspended' => 2], $mapped['skipped']);
  }

  public function testOptOutStillDeliveredWithoutRespectSuspension(): void {
    $contact = self::contact(20, ['email_primary.email' => 'opted-out@example.org', 'is_opt_out' => TRUE]);
    $this->assertCount(1, self::map([self::sub(20, 'email')], [$contact]));
  }

  public function testSuspensionOnlyAppliesToEmailChannel(): void {
    $contact = self::contact(23, ['is_opt_out' => TRUE, 'do_not_email' => TRUE]);
    $this->assertCount(1, self::map([self::sub(23, 'telegram', '5')], [$contact], ['respect_suspension' => TRUE]));
  }

  public function testLocationTypeAddressPreferredWithPrimaryFallback(): void {
    $contacts = [
      self::contact(24, [
        'email_primary.email' => 'primary@example.org',
        'herald_loc_email.email' => 'work@example.org',
      ]),
      self::contact(25, ['email_primary.email' => 'primary2@example.org']),
    ];
    $mapped = self::map([self::sub(24, 'email'), self::sub(25, 'email')], $contacts, ['location_type_id' => 3]);
    $this->assertSame(
      ['work@example.org', 'primary2@example.org'],
      array_column(array_column($mapped, 'config'), 'email_to'),
    );
  }

  public function testOnHoldLocationAddressFallsBackToPrimary(): void {
    $contact = self::contact(26, [
      'email_primary.email' => 'primary@example.org',
      'herald_loc_email.email' => 'work@example.org',
      'herald_loc_email.on_hold' => 1,
    ]);
    $mapped = self::detailed(
      [self::sub(26, 'email')],
      [$contact],
      ['location_type_id' => 3, 'respect_suspension' => TRUE],
    );
    $this->assertSame('primary@example.org', $mapped['recipients'][0]['config']['email_to']);
    $this->assertSame([], $mapped['skipped']);
  }

  public function testAllAddressesOnHoldSuspends(): void {
    $contact = self::contact(27, [
      'email_primary.email' => 'primary@example.org',
      'email_primary.on_hold' => 1,
      'herald_loc_email.email' => 'work@example.org',
      'herald_loc_email.on_hold' => 1,
    ]);
    $mapped = self::detailed(
      [self::sub(27, 'email')],
      [$contact],
      ['location_type_id' => 3, 'respect_suspension' => TRUE],
    );
    $this->assertSame([], $mapped['recipients']);
    $this->assertSame(['suspended' => 1], $mapped['skipped']);
  }

  public function testDuplicateJoinRowsProduceOneSendPerSubscription(): void {
    $row = self::contact(28, [
      'email_primary.email' => 'primary@example.org',
      'herald_loc_email.email' => 'work-a@example.org',
    ]);
    $dup = ['herald_loc_email.email' => 'work-b@example.org'] + $row;
    $mapped = self::detailed([self::sub(28, 'email')], [$row, $dup], ['location_type_id' => 3]);
    $this->assertCount(1, $mapped['recipients']);
    $this->assertSame('work-a@example.org', $mapped['recipients'][0]['config']['email_to']);
  }

  public function testScopedSubscriptionOnlyMatchesItsEntity(): void {
    $subscriptions = [self::sub(30, 'telegram', 'A', 'Event', 5), self::sub(31, 'telegram', 'B')];
    $contacts = [self::contact(30), self::contact(31)];

    // Matching scope: both reached.
    $match = self::detailed($subscriptions, $contacts, ['scope' => ['entity_type' => 'Event', 'entity_id' => 5]]);
    $this->assertSame([30, 31], array_column($match['recipients'], 'contact_id'));

    // Different instance: only the global subscription.
    $other = self::detailed($subscriptions, $contacts, ['scope' => ['entity_type' => 'Event', 'entity_id' => 6]]);
    $this->assertSame([31], array_column($other['recipients'], 'contact_id'));
    $this->assertSame(['scope_mismatch' => 1], $other['skipped']);

    // Unscoped notification: scoped subscriptions stay silent.
    $unscoped = self::detailed($subscriptions, $contacts, []);
    $this->assertSame([31], array_column($unscoped['recipients'], 'contact_id'));
  }

  public function testAScopedAndAGlobalRowOfOneContactAreIndependent(): void {
    $subscriptions = [self::sub(30, 'telegram', 'A', 'Event', 5), self::sub(30, 'email')];
    $contacts = [self::contact(30, ['email_primary.email' => 'ada@example.org'])];
    $this->assertSame(['email'], array_column(self::map($subscriptions, $contacts), 'channel'));
    $this->assertSame(
      ['telegram', 'email'],
      array_column(self::map($subscriptions, $contacts, ['scope' => [
        'entity_type' => 'Event',
        'entity_id' => 5,
      ]]), 'channel'),
    );
  }

  public function testDeliverableSecondJoinRowRescuesTheContact(): void {
    // Two work addresses, first on hold: the contact is not written off on the
    // strength of the first join row.
    $contacts = [
      self::contact(21, ['herald_loc_email.email' => 'hold@example.org', 'herald_loc_email.on_hold' => 1]),
      self::contact(21, ['herald_loc_email.email' => 'ok@example.org', 'herald_loc_email.on_hold' => 0]),
    ];
    $mapped = self::detailed([self::sub(21, 'email')], $contacts, [
      'location_type_id' => 3,
      'respect_suspension' => TRUE,
    ]);
    $this->assertSame(
      [['contact_id' => 21, 'channel' => 'email', 'config' => ['email_to' => 'ok@example.org']]],
      $mapped['recipients'],
    );
    $this->assertSame([], $mapped['skipped']);
  }

  public function testLocationAddressOfLaterRowBeatsThePrimaryFallback(): void {
    $contacts = [
      self::contact(22, [
        'email_primary.email' => 'primary@example.org',
        'herald_loc_email.email' => 'hold@example.org',
        'herald_loc_email.on_hold' => 1,
      ]),
      self::contact(22, ['email_primary.email' => 'primary@example.org', 'herald_loc_email.email' => 'ok@example.org']),
    ];
    $mapped = self::map([self::sub(22, 'email')], $contacts, ['location_type_id' => 3, 'respect_suspension' => TRUE]);
    $this->assertSame('ok@example.org', $mapped[0]['config']['email_to']);
  }

  public function testContactWithAllAddressesOnHoldCountsAsSuspendedOnce(): void {
    $contacts = [
      self::contact(23, ['herald_loc_email.email' => 'a@example.org', 'herald_loc_email.on_hold' => 1]),
      self::contact(23, ['herald_loc_email.email' => 'b@example.org', 'herald_loc_email.on_hold' => 1]),
    ];
    $mapped = self::detailed([self::sub(23, 'email')], $contacts, [
      'location_type_id' => 3,
      'respect_suspension' => TRUE,
    ]);
    $this->assertSame([], $mapped['recipients']);
    $this->assertSame(['suspended' => 1], $mapped['skipped']);
  }

}
