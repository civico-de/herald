<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Topic\Router;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

// ckfmt wraps this declaration with 2-space continuations; the sniff wants 4.
/**
 * Headless tests for the recipient-resolution queries: group fan-out,
 * location-type email join, and source-entity-scoped subscriber lookup.
 *
 * @group headless
 */
class RecipientQueryTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function setUp(): void {
    parent::setUp();
    \Civi\Herald\ChannelFixture::add('telegram', 'telegram', ['bot_token' => bin2hex(random_bytes(8))], TRUE);
  }

  /**
   * @param array<string, mixed> $values
   */
  private function createContact(array $values = []): int {
    $create = \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Herald')
      ->addValue('last_name', 'Fixture' . random_int(1000, 9999));
    foreach ($values as $key => $value) {
      $create->addValue($key, $value);
    }
    return (int) $create->execute()->first()['id'];
  }

  /**
   * @param array<string, mixed> $values
   */
  private function subscribe(int $contactId, array $values): void {
    \Civi\Api4\CustomValue::create(Subscriptions::GROUP, checkPermissions: FALSE)
      ->setValues(['entity_id' => $contactId] + $values)
      ->execute();
  }

  private function addEmail(int $contactId, string $email, ?int $locationTypeId = NULL, bool $onHold = FALSE): void {
    $create = \Civi\Api4\Email::create(checkPermissions: FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('email', $email)
      ->addValue('on_hold', $onHold);
    if ($locationTypeId !== NULL) {
      $create->addValue('location_type_id', $locationTypeId);
    }
    $create->execute();
  }

  private function createGroupWith(int ...$contactIds): int {
    $groupId = (int) \Civi\Api4\Group::create(checkPermissions: FALSE)
      ->addValue('name', 'herald_test_group_' . random_int(1000, 9999))
      ->addValue('title', 'Herald test group')
      ->execute()
      ->first()['id'];
    foreach ($contactIds as $contactId) {
      \Civi\Api4\GroupContact::create(checkPermissions: FALSE)
        ->addValue('group_id', $groupId)
        ->addValue('contact_id', $contactId)
        ->addValue('status', 'Added')
        ->execute();
    }
    return $groupId;
  }

  public function testGroupFanOutMixesGroupSubscriptionsAndEmailFallback(): void {
    $telegramContact = $this->createContact();
    $this->subscribe($telegramContact, ['channel' => 'telegram', 'recipient' => '111', 'for_groups' => TRUE]);
    $plainContact = $this->createContact();
    $this->addEmail($plainContact, 'group-fallback@example.org');
    // A row without the group flag serves topics only.
    $this->subscribe($plainContact, ['channel' => 'telegram', 'recipient' => '222', 'topics' => ['news']]);
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

  public function testContactWithTwoLocationEmailsGetsExactlyOneSend(): void {
    $contact = $this->createContact();
    $this->addEmail($contact, 'home@example.org');
    $this->addEmail($contact, 'work-a@example.org', 2);
    $this->addEmail($contact, 'work-b@example.org', 2);
    $groupId = $this->createGroupWith($contact);

    $recipients = (new GroupRecipients())->findByGroup($groupId, ['location_type_id' => 2]);
    $this->assertCount(1, $recipients);
    $this->assertSame($contact, $recipients[0]['contact_id']);
    $this->assertContains($recipients[0]['config']['email_to'], ['work-a@example.org', 'work-b@example.org']);
  }

  public function testScopedSubscriberLookupFiltersByEntityInstance(): void {
    \Civi::settings()->set(Router::SETTING, ['news' => ['subscribable' => TRUE]]);
    SubscribableTopics::sync();
    $scoped = $this->createContact();
    $this->subscribe($scoped, [
      'channel' => 'telegram',
      'recipient' => 'scoped',
      'topics' => ['news'],
      'source_entity_type' => 'Event',
      'source_entity_id' => 12_345,
    ]);
    $global = $this->createContact();
    $this->subscribe($global, ['channel' => 'telegram', 'recipient' => 'global', 'topics' => ['news']]);

    $lookup = new SubscriberLookup();
    $matching = $lookup->findByTopic('news', [
      'scope' => ['entity_type' => 'Event', 'entity_id' => 12_345],
    ]);
    $this->assertSame([$scoped, $global], array_column($matching, 'contact_id'));

    $other = $lookup->findByTopic('news', [
      'scope' => ['entity_type' => 'Event', 'entity_id' => 99_999],
    ]);
    $this->assertSame([$global], array_column($other, 'contact_id'));

    $unscoped = $lookup->findByTopic('news');
    $this->assertSame([$global], array_column($unscoped, 'contact_id'));
  }

  public function testEachMatchingRowOfALivingContactIsARecipient(): void {
    \Civi::settings()->set(Router::SETTING, ['news' => ['subscribable' => TRUE], 'ops' => ['subscribable' => TRUE]]);
    SubscribableTopics::sync();
    $contact = $this->createContact();
    $this->addEmail($contact, 'own@example.org');
    $this->subscribe($contact, ['channel' => 'telegram', 'recipient' => '1', 'topics' => ['news', 'ops']]);
    $this->subscribe($contact, ['channel' => 'email', 'topics' => ['news']]);
    $this->subscribe($contact, ['channel' => 'telegram', 'recipient' => '2', 'topics' => ['ops']]);
    $deleted = $this->createContact(['is_deleted' => TRUE]);
    $this->subscribe($deleted, ['channel' => 'telegram', 'recipient' => '3', 'topics' => ['news']]);

    $this->assertSame(
      [['chat_id' => '1'], ['email_to' => 'own@example.org']],
      array_column((new SubscriberLookup())->findByTopic('news'), 'config'),
    );
    $this->assertSame(
      2,
      Subscriptions::subscribers('news'),
      'a trashed contact keeps its rows and still holds the topic',
    );
  }

}
