<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Api4\Contact;
use Civi\Api4\Event;
use Civi\Api4\Participant;
use Civi\Herald\Renderer\TemplateRenderer;
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
      ->execute()->single()['id'];
    $eventId = Event::create(checkPermissions: FALSE)
      ->setValues(['title' => 'Workshop A', 'event_type_id' => 1, 'start_date' => '2030-01-01 10:00:00'])
      ->execute()->single()['id'];
    $participant = Participant::create(checkPermissions: FALSE)
      ->setValues([
        'contact_id' => $contactId,
        'event_id' => $eventId,
        'status_id:name' => 'Cancelled',
        'role_id:name' => 'Attendee',
      ])
      ->execute()->single();

    $vars = ContextVars::build(['contact_id' => $contactId, 'Participant' => $participant]);

    $this->assertSame('Workshop A', $vars['event']['title']);
    $this->assertSame('Attendee', $vars['participant']['role']);
    $this->assertSame('Cancelled', $vars['participant']['status']);

    $body = (new TemplateRenderer())->render('herald_event_cancellation_alert', $vars);
    $this->assertStringContainsString('Event registration cancelled', $body);
    $this->assertStringContainsString('Workshop A', $body);
    $this->assertStringNotContainsString('Lovelace', $body);
  }

}
