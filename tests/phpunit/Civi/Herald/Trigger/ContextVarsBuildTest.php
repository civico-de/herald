<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Api4\Contact;
use Civi\Api4\Event;
use Civi\Api4\Group;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Group and bare Event trigger payloads become template variables; a deleted
 * event falls back to the trigger's own snapshot, and a failing lookup leaves
 * only its own variable out.
 *
 * @group headless
 */
class ContextVarsBuildTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * @var list<string> tables hidden behind a column-less temporary table
   */
  private array $shadowed = [];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function tearDown(): void {
    foreach ($this->shadowed as $table) {
      \CRM_Core_DAO::executeQuery("DROP TEMPORARY TABLE `{$table}`");
    }
    $this->shadowed = [];
    parent::tearDown();
  }

  /**
   * Makes every lookup on $table fail with a real SQL error. Temporary tables
   * neither commit implicitly nor outlive the connection.
   */
  private function breakTable(string $table): void {
    \CRM_Core_DAO::executeQuery("CREATE TEMPORARY TABLE `{$table}` (`id` int unsigned)");
    $this->shadowed[] = $table;
  }

  public function testGroupContactNamesTheGroup(): void {
    $groupId = (int) Group::create(checkPermissions: FALSE)
      ->addValue('title', 'Herald test newsletter')
      ->addValue('name', 'herald_test_newsletter')
      ->execute()
      ->first()['id'];

    $vars = ContextVars::build(['GroupContact' => ['group_id' => $groupId]]);

    $this->assertSame(['id' => $groupId, 'title' => 'Herald test newsletter'], $vars['group']);
  }

  public function testEventIsReadFromTheLiveRow(): void {
    $eventId = (int) Event::create(checkPermissions: FALSE)
      ->addValue('title', 'Herald test summit')
      ->addValue('event_type_id', 1)
      ->addValue('start_date', '2026-11-05 18:00:00')
      ->addValue('is_active', value: TRUE)
      ->execute()
      ->first()['id'];

    $vars = ContextVars::build(['Event' => ['id' => $eventId, 'title' => 'Stale title', 'is_active' => 0]]);

    $this->assertSame(
      [
        'id' => $eventId,
        'title' => 'Herald test summit',
        'start_date' => '2026-11-05 18:00:00',
        'is_active' => 1,
      ],
      $vars['event'],
    );
  }

  /**
   * APIv4 leaves templates and trashed contacts out of a get unless it fetches
   * by id; the lookups must still find them.
   */
  public function testAnEventTemplateAndATrashedContactAreFound(): void {
    $eventId = (int) Event::create(checkPermissions: FALSE)
      ->addValue('template_title', 'Herald test template')
      ->addValue('title', 'Herald template event')
      ->addValue('event_type_id', 1)
      ->addValue('is_template', value: TRUE)
      ->execute()
      ->first()['id'];
    $contactId = (int) Contact::create(checkPermissions: FALSE)
      ->addValue('first_name', 'Trashed')
      ->addValue('last_name', 'Testcontact')
      ->addValue('is_deleted', value: TRUE)
      ->execute()
      ->first()['id'];

    $vars = ContextVars::build(['contact_id' => $contactId, 'Event' => ['id' => $eventId]]);

    $this->assertSame('Herald template event', $vars['event']['title']);
    $this->assertSame('Trashed Testcontact', $vars['contact']['display_name']);
  }

  public function testDeletedEventKeepsTheTriggerSnapshot(): void {
    $vars = ContextVars::build([
      'Event' => [
        'id' => 999_999_999,
        'title' => 'Gone summit',
        'start_date' => '2026-01-01 10:00:00',
        'is_active' => '0',
      ],
    ]);

    $this->assertSame(
      [
        'id' => 999_999_999,
        'title' => 'Gone summit',
        'start_date' => '2026-01-01 10:00:00',
        'is_active' => 0,
      ],
      $vars['event'],
    );
  }

  public function testPayloadWithoutIdsAddsNothing(): void {
    $this->assertSame([], ContextVars::build(['GroupContact' => ['group_id' => 0], 'Event' => ['title' => 'No id']]));
  }

  /**
   * @return array<string, array{string, array<string, mixed>, list<string>}>
   */
  public static function failingLookups(): array {
    return [
      'contact' => ['civicrm_contact', ['contact_id' => 1], ['contact']],
      'group' => ['civicrm_group', ['GroupContact' => ['group_id' => 1]], ['group']],
      'membership' => [
        'civicrm_membership_type',
        ['Membership' => ['id' => 1, 'contact_id' => 0, 'membership_type_id' => 1, 'status_id' => 1]],
        ['membership'],
      ],
      'participant' => [
        'civicrm_event',
        ['Participant' => ['id' => 1, 'contact_id' => 0, 'event_id' => 1, 'role_id' => '1', 'status_id' => 1]],
        ['event', 'participant'],
      ],
      'event' => ['civicrm_event', ['Event' => ['id' => 1, 'title' => 'Snapshot']], ['event']],
    ];
  }

  /**
   * @dataProvider failingLookups
   * @param array<string, mixed> $context
   * @param list<string> $lost
   */
  public function testAFailingLookupLeavesOnlyItsVariableOut(string $table, array $context, array $lost): void {
    $this->breakTable($table);

    $vars = ContextVars::build($context);

    $this->assertSame([], array_values(array_intersect($lost, array_keys($vars))));
  }

  public function testOtherVariablesSurviveAFailingLookup(): void {
    $this->breakTable('civicrm_group');

    $vars = ContextVars::build([
      'GroupContact' => ['group_id' => 1],
      'Event' => ['id' => 999_999_999, 'title' => 'Gone summit'],
    ]);

    $this->assertArrayNotHasKey('group', $vars);
    $this->assertSame('Gone summit', $vars['event']['title'] ?? NULL);
  }

  public function testAnUnreadableStoredRowKeepsThePayload(): void {
    $this->breakTable('civicrm_membership');

    $vars = ContextVars::build(['Membership' => ['id' => 1]]);

    $this->assertSame(['membership_type' => '', 'status' => ''], $vars['membership'] ?? NULL);
  }

}
