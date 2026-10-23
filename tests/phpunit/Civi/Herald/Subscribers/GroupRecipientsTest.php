<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for GroupRecipients::mapGroupRows.
 * @group unit
 */
class GroupRecipientsTest extends TestCase {

  private const TYPES = ['telegram' => 'telegram', 'email' => 'email'];

  private static function map(array $rows, array $options = [], array $subscriptions = []): array {
    return GroupRecipients::mapGroupRows($rows, $subscriptions, $options, self::TYPES, 'email');
  }

  private function row(array $overrides): array {
    return $overrides
    + [
      'id' => 0,
      'email_primary.email' => '',
      'email_primary.on_hold' => FALSE,
      'do_not_email' => FALSE,
      'is_opt_out' => FALSE,
    ];
  }

  public function testMemberWithAGroupSubscriptionUsesItsChannel(): void {
    $mapped = self::map([$this->row(['id' => 1])], [], [Subscriptions::row(1, 'telegram', '111', '', 0)]);
    $this->assertSame(
      [['contact_id' => 1, 'channel' => 'telegram', 'config' => ['chat_id' => '111']]],
      $mapped['recipients'],
    );
    $this->assertSame([], $mapped['skipped']);
  }

  public function testMemberWithoutGroupSubscriptionFallsBackToOwnEmail(): void {
    $rows = [$this->row(['id' => 2, 'email_primary.email' => 'a@example.org'])];
    $mapped = self::map($rows);
    $this->assertSame(
      [['contact_id' => 2, 'channel' => 'email', 'config' => ['email_to' => 'a@example.org']]],
      $mapped['recipients'],
    );
  }

  public function testMemberWithAnUnusableGroupSubscriptionFallsBackToEmail(): void {
    $rows = [$this->row(['id' => 3, 'email_primary.email' => 'b@example.org'])];
    $mapped = self::map(
      $rows,
      [],
      [Subscriptions::row(3, 'telegram', '', '', 0), Subscriptions::row(3, 'matrix', 'x', '', 0)],
    );
    $this->assertSame(
      [['contact_id' => 3, 'channel' => 'email', 'config' => ['email_to' => 'b@example.org']]],
      $mapped['recipients'],
    );
  }

  public function testMemberGetsEachUsableGroupSubscriptionOnceAndNoFallback(): void {
    $rows = [$this->row(['id' => 4, 'email_primary.email' => 'own@example.org'])];
    $subscriptions = [
      Subscriptions::row(4, 'telegram', '1', 'Event', 42),
      Subscriptions::row(4, 'telegram', '1', '', 0),
      Subscriptions::row(4, 'email', 'team@example.org', '', 0),
    ];
    $this->assertSame(
      [
        ['contact_id' => 4, 'channel' => 'telegram', 'config' => ['chat_id' => '1']],
        ['contact_id' => 4, 'channel' => 'email', 'config' => ['email_to' => 'team@example.org']],
      ],
      self::map($rows, [], $subscriptions)['recipients'],
    );
  }

  public function testOnlyTheMembersOwnSubscriptionsCount(): void {
    $mapped = self::map(
      [$this->row(['id' => 5, 'email_primary.email' => 'own@example.org'])],
      [],
      [Subscriptions::row(6, 'telegram', '1', '', 0)],
    );
    $this->assertSame([['email_to' => 'own@example.org']], array_column($mapped['recipients'], 'config'));
  }

  public function testMemberWithoutAnyRecipientCountedAsSkipped(): void {
    $mapped = self::map([$this->row(['id' => 4])]);
    $this->assertSame([], $mapped['recipients']);
    $this->assertSame(['no_recipient' => 1], $mapped['skipped']);
  }

  public function testSuspendedEmailRecipientSkippedWhenOptionSet(): void {
    $rows = [
      $this->row(['id' => 5, 'email_primary.email' => 'c@example.org', 'is_opt_out' => TRUE]),
      $this->row(['id' => 6, 'email_primary.email' => 'd@example.org', 'email_primary.on_hold' => 1]),
      $this->row(['id' => 7, 'email_primary.email' => 'e@example.org']),
    ];
    $mapped = self::map($rows, ['respect_suspension' => TRUE]);
    $this->assertSame([7], array_column($mapped['recipients'], 'contact_id'));
    $this->assertSame(['suspended' => 2], $mapped['skipped']);
  }

  public function testSuspensionIgnoredByDefaultExceptDoNotEmail(): void {
    $rows = [
      $this->row(['id' => 5, 'email_primary.email' => 'c@example.org', 'is_opt_out' => TRUE]),
      $this->row(['id' => 8, 'email_primary.email' => 'f@example.org', 'do_not_email' => TRUE]),
    ];
    $mapped = self::map($rows);
    $this->assertSame([5], array_column($mapped['recipients'], 'contact_id'));
    $this->assertSame(['suspended' => 1], $mapped['skipped']);
  }

  public function testLocationTypeEmailPreferredOverPrimary(): void {
    $rows = [
      $this->row([
        'id' => 9,
        'email_primary.email' => 'primary@example.org',
        'herald_loc_email.email' => 'work@example.org',
        'herald_loc_email.on_hold' => FALSE,
      ]),
      // No address of the requested location type → primary fallback.
      $this->row(['id' => 10, 'email_primary.email' => 'only-primary@example.org']),
    ];
    $mapped = self::map($rows, ['location_type_id' => 3]);
    $this->assertSame('work@example.org', $mapped['recipients'][0]['config']['email_to']);
    $this->assertSame('only-primary@example.org', $mapped['recipients'][1]['config']['email_to']);
  }

  public function testOnHoldLocationAddressFallsBackToUsablePrimary(): void {
    $rows = [
      $this->row([
        'id' => 11,
        'email_primary.email' => 'primary@example.org',
        'herald_loc_email.email' => 'work@example.org',
        'herald_loc_email.on_hold' => 1,
      ]),
    ];
    $mapped = self::map($rows, ['location_type_id' => 3, 'respect_suspension' => TRUE]);
    $this->assertSame('primary@example.org', $mapped['recipients'][0]['config']['email_to']);
    $this->assertSame([], $mapped['skipped']);
  }

  public function testAllAddressesOnHoldSuspendsTheContact(): void {
    $rows = [
      $this->row([
        'id' => 12,
        'email_primary.email' => 'primary@example.org',
        'email_primary.on_hold' => 1,
        'herald_loc_email.email' => 'work@example.org',
        'herald_loc_email.on_hold' => 1,
      ]),
    ];
    $mapped = self::map($rows, ['location_type_id' => 3, 'respect_suspension' => TRUE]);
    $this->assertSame([], $mapped['recipients']);
    $this->assertSame(['suspended' => 1], $mapped['skipped']);
  }

  public function testDuplicateJoinRowsProduceOneSendPerContact(): void {
    // Two rows for the same contact (multiple location-type emails).
    $rows = [
      $this->row([
        'id' => 13,
        'email_primary.email' => 'primary@example.org',
        'herald_loc_email.email' => 'work-a@example.org',
      ]),
      $this->row([
        'id' => 13,
        'email_primary.email' => 'primary@example.org',
        'herald_loc_email.email' => 'work-b@example.org',
      ]),
    ];
    $mapped = self::map($rows, ['location_type_id' => 3]);
    $this->assertCount(1, $mapped['recipients']);
    $this->assertSame('work-a@example.org', $mapped['recipients'][0]['config']['email_to']);
  }

  public function testDeliverableSecondJoinRowRescuesTheContact(): void {
    // First work address on hold, second fine: the contact must still get the
    // mail — dedup may only strike once a deliverable address was picked.
    $rows = [
      $this->row([
        'id' => 14,
        'herald_loc_email.email' => 'hold@example.org',
        'herald_loc_email.on_hold' => 1,
      ]),
      $this->row([
        'id' => 14,
        'herald_loc_email.email' => 'ok@example.org',
        'herald_loc_email.on_hold' => 0,
      ]),
    ];
    $mapped = self::map($rows, ['location_type_id' => 3, 'respect_suspension' => TRUE]);
    $this->assertSame(
      [['contact_id' => 14, 'channel' => 'email', 'config' => ['email_to' => 'ok@example.org']]],
      $mapped['recipients'],
    );
    $this->assertSame([], $mapped['skipped']);
  }

  public function testFallbackGoesThroughTheContactEmailChannel(): void {
    $mapped = GroupRecipients::mapGroupRows(
      [$this->row(['id' => 9, 'email_primary.email' => 'ada@example.org'])],
      [],
      [],
      self::TYPES,
      'staff_mail',
    );
    $this->assertSame('staff_mail', $mapped['recipients'][0]['channel']);
  }

}
