<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Api4\Contact;
use Civi\Api4\Event;
use Civi\Api4\Membership;
use Civi\Api4\MembershipType;
use Civi\Api4\Participant;
use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Util\StoredFields;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class ParticipantVarsTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function testParticipantCarriesStatusAndRoleLabels(): void {
    $contactId = Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Individual', 'first_name' => 'Ada', 'last_name' => 'Lovelace'])
      ->execute()
      ->single()['id'];
    $eventId = Event::create(checkPermissions: FALSE)
      ->setValues(['title' => 'Workshop A', 'event_type_id' => 1, 'start_date' => '2030-01-01 10:00:00'])
      ->execute()
      ->single()['id'];
    $participant = Participant::create(checkPermissions: FALSE)
      ->setValues([
        'contact_id' => $contactId,
        'event_id' => $eventId,
        'status_id:name' => 'Cancelled',
        'role_id:name' => 'Attendee',
      ])
      ->execute()
      ->single();

    $vars = ContextVars::build(['contact_id' => $contactId, 'Participant' => $participant]);

    $this->assertSame('Workshop A', $vars['event']['title']);
    $this->assertSame('Attendee', $vars['participant']['role']);
    $this->assertSame('Cancelled', $vars['participant']['status']);

    $body = (new TemplateRenderer())->render('herald_event_cancellation_alert', $vars);
    $this->assertStringContainsString('Event registration cancelled', $body);
    $this->assertStringContainsString('Workshop A', $body);
    $this->assertStringNotContainsString('Lovelace', $body);
  }

  public function testPartialParticipantPayloadIsCompletedFromTheStoredRow(): void {
    $contactId = Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Individual', 'first_name' => 'Ada', 'last_name' => 'Lovelace'])
      ->execute()
      ->single()['id'];
    $eventId = Event::create(checkPermissions: FALSE)
      ->setValues(['title' => 'Workshop B', 'event_type_id' => 1, 'start_date' => '2030-01-01 10:00:00'])
      ->execute()
      ->single()['id'];
    $participantId = Participant::create(checkPermissions: FALSE)
      ->setValues([
        'contact_id' => $contactId,
        'event_id' => $eventId,
        'status_id:name' => 'Registered',
        'role_id:name' => ['Speaker', 'Attendee'],
      ])
      ->execute()
      ->single()['id'];

    // A status-only update: the post hook carries the id and the written field.
    $vars = ContextVars::build(['contact_id' => 0, 'Participant' => ['id' => $participantId, 'status_id' => 4]]);

    $this->assertSame($contactId, $vars['contact']['id']);
    $this->assertSame('Workshop B', $vars['event']['title']);
    $this->assertSame('Speaker', $vars['participant']['role']);
    $this->assertSame('Cancelled', $vars['participant']['status']);
  }

  public function testPartialTestParticipantPayloadIsCompletedFromTheStoredRow(): void {
    $contactId = Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Individual', 'first_name' => 'Ada', 'last_name' => 'Lovelace'])
      ->execute()
      ->single()['id'];
    $eventId = Event::create(checkPermissions: FALSE)
      ->setValues(['title' => 'Workshop C', 'event_type_id' => 1, 'start_date' => '2030-01-01 10:00:00'])
      ->execute()
      ->single()['id'];
    $participantId = Participant::create(checkPermissions: FALSE)
      ->setValues([
        'contact_id' => $contactId,
        'event_id' => $eventId,
        'is_test' => TRUE,
        'status_id:name' => 'Registered',
        'role_id:name' => 'Attendee',
      ])
      ->execute()
      ->single()['id'];

    // Fetching by id skips APIv4's is_test = 0 default, so test registrations complete too.
    $vars = ContextVars::build(['contact_id' => 0, 'Participant' => ['id' => $participantId, 'status_id' => 4]]);

    $this->assertSame($contactId, $vars['contact']['id']);
    $this->assertSame('Workshop C', $vars['event']['title']);
  }

  public function testPartialMembershipPayloadIsCompletedFromTheStoredRow(): void {
    $contactId = Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Individual', 'first_name' => 'Ada', 'last_name' => 'Lovelace'])
      ->execute()
      ->single()['id'];
    $typeId = MembershipType::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'Herald Test Type',
        'member_of_contact_id' => 1,
        'financial_type_id:name' => 'Member Dues',
        'duration_unit' => 'year',
        'duration_interval' => 1,
        'period_type' => 'rolling',
      ])
      ->execute()
      ->single()['id'];
    $membershipId = Membership::create(checkPermissions: FALSE)
      ->setValues(['contact_id' => $contactId, 'membership_type_id' => $typeId, 'status_id:name' => 'New'])
      ->execute()
      ->single()['id'];

    // A status-only update: the post hook carries the id and the written field.
    $grace = \CRM_Core_PseudoConstant::getKey('CRM_Member_BAO_Membership', 'status_id', 'Grace');
    $vars = ContextVars::build(['contact_id' => 0, 'Membership' => ['id' => $membershipId, 'status_id' => $grace]]);

    $this->assertSame($contactId, $vars['contact']['id']);
    $this->assertSame('Herald Test Type', $vars['membership']['membership_type']);
    $this->assertSame('Grace', $vars['membership']['status']);
  }

  public function testACompletePayloadIsNotReadAgain(): void {
    $get = $this->createMock(\Civi\Api4\Generic\DAOGetAction::class);
    $get->expects($this->never())->method('execute');
    $payload = ['id' => 7, 'status_id' => 2];
    $this->assertSame($payload, StoredFields::complete($get, $payload, ['status_id']));
  }

  public function testAFailedReadKeepsThePayload(): void {
    $get = Membership::get(checkPermissions: FALSE)->addWhere('no_such_field', '=', 1);
    $payload = ['id' => 7, 'status_id' => 2];
    $this->assertSame($payload, StoredFields::complete($get, $payload, ['contact_id']));
  }

}
