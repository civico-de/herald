<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for GroupRecipients::mapGroupRows.
 * @group unit
 */
class GroupRecipientsTest extends TestCase {

  private function row(array $overrides): array {
    return $overrides
    + [
      'id' => 0,
      'herald_preferences.preferred_channel' => '',
      'herald_preferences.telegram_chat_id' => '',
      'herald_preferences.pushover_user_key' => '',
      'herald_preferences.ntfy_topic' => '',
      'herald_preferences.webhook_url' => '',
      'email_primary.email' => '',
      'email_primary.on_hold' => FALSE,
      'do_not_email' => FALSE,
      'is_opt_out' => FALSE,
    ];
  }

  public function testMemberWithPreferenceUsesOwnChannel(): void {
    $rows = [
      $this->row([
        'id' => 1,
        'herald_preferences.preferred_channel' => 'telegram',
        'herald_preferences.telegram_chat_id' => '111',
      ]),
    ];
    $mapped = GroupRecipients::mapGroupRows($rows);
    $this->assertSame(
      [['contact_id' => 1, 'channel' => 'telegram', 'config' => ['chat_id' => '111']]],
      $mapped['recipients'],
    );
    $this->assertSame([], $mapped['skipped']);
  }

  public function testMemberWithoutPreferenceFallsBackToOwnEmail(): void {
    $rows = [$this->row(['id' => 2, 'email_primary.email' => 'a@example.org'])];
    $mapped = GroupRecipients::mapGroupRows($rows);
    $this->assertSame(
      [['contact_id' => 2, 'channel' => 'email', 'config' => ['email_to' => 'a@example.org']]],
      $mapped['recipients'],
    );
  }

  public function testMemberWithBrokenPreferenceFallsBackToEmail(): void {
    // Preferred channel picked but recipient id missing → email fallback.
    $rows = [
      $this->row([
        'id' => 3,
        'herald_preferences.preferred_channel' => 'telegram',
        'email_primary.email' => 'b@example.org',
      ]),
    ];
    $mapped = GroupRecipients::mapGroupRows($rows);
    $this->assertSame('email', $mapped['recipients'][0]['channel']);
  }

  public function testMemberWithoutAnyRecipientCountedAsSkipped(): void {
    $mapped = GroupRecipients::mapGroupRows([$this->row(['id' => 4])]);
    $this->assertSame([], $mapped['recipients']);
    $this->assertSame(['no_recipient' => 1], $mapped['skipped']);
  }

  public function testSuspendedEmailRecipientSkippedWhenOptionSet(): void {
    $rows = [
      $this->row(['id' => 5, 'email_primary.email' => 'c@example.org', 'is_opt_out' => TRUE]),
      $this->row(['id' => 6, 'email_primary.email' => 'd@example.org', 'email_primary.on_hold' => 1]),
      $this->row(['id' => 7, 'email_primary.email' => 'e@example.org']),
    ];
    $mapped = GroupRecipients::mapGroupRows($rows, ['respect_suspension' => TRUE]);
    $this->assertSame([7], array_column($mapped['recipients'], 'contact_id'));
    $this->assertSame(['suspended' => 2], $mapped['skipped']);
  }

  public function testSuspensionIgnoredByDefaultExceptDoNotEmail(): void {
    $rows = [
      $this->row(['id' => 5, 'email_primary.email' => 'c@example.org', 'is_opt_out' => TRUE]),
      $this->row(['id' => 8, 'email_primary.email' => 'f@example.org', 'do_not_email' => TRUE]),
    ];
    $mapped = GroupRecipients::mapGroupRows($rows);
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
    $mapped = GroupRecipients::mapGroupRows($rows, ['location_type_id' => 3]);
    $this->assertSame('work@example.org', $mapped['recipients'][0]['config']['email_to']);
    $this->assertSame('only-primary@example.org', $mapped['recipients'][1]['config']['email_to']);
  }

  public function testOnHoldCheckedOnTheResolvedAddress(): void {
    // Location address on hold → suspended even though primary is fine.
    $rows = [
      $this->row([
        'id' => 11,
        'email_primary.email' => 'primary@example.org',
        'herald_loc_email.email' => 'work@example.org',
        'herald_loc_email.on_hold' => 1,
      ]),
    ];
    $mapped = GroupRecipients::mapGroupRows($rows, ['location_type_id' => 3, 'respect_suspension' => TRUE]);
    $this->assertSame([], $mapped['recipients']);
    $this->assertSame(['suspended' => 1], $mapped['skipped']);
  }

}
