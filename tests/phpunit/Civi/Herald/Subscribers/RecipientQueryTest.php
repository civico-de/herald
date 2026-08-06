<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

// phpcs:disable Drupal.Classes.ClassDeclaration.InterfaceWrongIndent
// ckfmt wraps this declaration with 2-space continuations; the sniff wants 4.
/**
 * Headless tests for the recipient-resolution queries: group fan-out,
 * location-type email join, and source-entity-scoped subscriber lookup.
 *
 * @group headless
 */
class RecipientQueryTest extends TestCase implements
  HeadlessInterface,
  HookInterface,
  TransactionalInterface {

  // phpcs:enable Drupal.Classes.ClassDeclaration.InterfaceWrongIndent

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * @param array<string, mixed> $values
   */
  private function createContact(array $values = []): int {
    $create = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Herald')
      ->addValue('last_name', 'Fixture' . random_int(1000, 9999));
    foreach ($values as $key => $value) {
      $create->addValue($key, $value);
    }
    return (int) $create->execute()->first()['id'];
  }

  private function addEmail(int $contactId, string $email, ?int $locationTypeId = NULL, bool $onHold = FALSE): void {
    $create = \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('email', $email)
      ->addValue('on_hold', $onHold);
    if ($locationTypeId !== NULL) {
      $create->addValue('location_type_id', $locationTypeId);
    }
    $create->execute();
  }

  private function createGroupWith(int ...$contactIds): int {
    $groupId = (int) \Civi\Api4\Group::create(FALSE)
      ->addValue('name', 'herald_test_group_' . random_int(1000, 9999))
      ->addValue('title', 'Herald test group')
      ->execute()
      ->first()['id'];
    foreach ($contactIds as $contactId) {
      \Civi\Api4\GroupContact::create(FALSE)
        ->addValue('group_id', $groupId)
        ->addValue('contact_id', $contactId)
        ->addValue('status', 'Added')
        ->execute();
    }
    return $groupId;
  }

  public function testGroupFanOutMixesPreferencesAndEmailFallback(): void {
    $telegramContact = $this->createContact([
      'herald_preferences.preferred_channel' => 'telegram',
      'herald_preferences.telegram_chat_id' => '111',
    ]);
    $plainContact = $this->createContact();
    $this->addEmail($plainContact, 'group-fallback@example.org');
    $noRecipient = $this->createContact();
    $groupId = $this->createGroupWith($telegramContact, $plainContact, $noRecipient);

    $recipients = (new GroupRecipients())->findByGroup($groupId);
    $byContact = array_column($recipients, NULL, 'contact_id');
    $this->assertCount(2, $recipients);
    $this->assertSame('telegram', $byContact[$telegramContact]['channel']);
    $this->assertSame(['chat_id' => '111'], $byContact[$telegramContact]['config']);
    $this->assertSame('email', $byContact[$plainContact]['channel']);
    $this->assertSame(['email_to' => 'group-fallback@example.org'], $byContact[$plainContact]['config']);
  }

  public function testGroupFanOutRespectsSuspensionAndLocationType(): void {
    // Work = location type 2 in the standard install.
    $workContact = $this->createContact();
    $this->addEmail($workContact, 'home@example.org');
    $this->addEmail($workContact, 'work@example.org', 2);
    $optedOut = $this->createContact(['is_opt_out' => TRUE]);
    $this->addEmail($optedOut, 'optout@example.org');
    $groupId = $this->createGroupWith($workContact, $optedOut);

    $recipients = (new GroupRecipients())->findByGroup($groupId, [
      'respect_suspension' => TRUE,
      'location_type_id' => 2,
    ]);
    $this->assertCount(1, $recipients);
    $this->assertSame($workContact, $recipients[0]['contact_id']);
    $this->assertSame(['email_to' => 'work@example.org'], $recipients[0]['config']);
  }

  public function testScopedSubscriberLookupFiltersByEntityInstance(): void {
    $scoped = $this->createContact([
      'herald_preferences.preferred_channel' => 'telegram',
      'herald_preferences.telegram_chat_id' => 'scoped',
      'herald_preferences.subscriptions' => ['completed_alert'],
      'herald_preferences.source_entity_type' => 'Event',
      'herald_preferences.source_entity_id' => 12_345,
    ]);
    $global = $this->createContact([
      'herald_preferences.preferred_channel' => 'telegram',
      'herald_preferences.telegram_chat_id' => 'global',
      'herald_preferences.subscriptions' => ['completed_alert'],
    ]);

    $lookup = new SubscriberLookup();
    $matching = $lookup->findBySubscriptionType('completed_alert', [
      'scope' => ['entity_type' => 'Event', 'entity_id' => 12_345],
    ]);
    $this->assertSame([$scoped, $global], array_column($matching, 'contact_id'));

    $other = $lookup->findBySubscriptionType('completed_alert', [
      'scope' => ['entity_type' => 'Event', 'entity_id' => 99_999],
    ]);
    $this->assertSame([$global], array_column($other, 'contact_id'));

    $unscoped = $lookup->findBySubscriptionType('completed_alert');
    $this->assertSame([$global], array_column($unscoped, 'contact_id'));
  }

}
