<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Api4\Event;
use Civi\Api4\Group;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Group and bare Event trigger payloads become template variables; a deleted
 * event falls back to the trigger's own snapshot.
 *
 * @group headless
 */
class ContextVarsBuildTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
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

    $this->assertSame([
      'id' => $eventId,
      'title' => 'Herald test summit',
      'start_date' => '2026-11-05 18:00:00',
      'is_active' => 1,
    ], $vars['event']);
  }

  public function testDeletedEventKeepsTheTriggerSnapshot(): void {
    $vars = ContextVars::build([
      'Event' => ['id' => 999_999_999, 'title' => 'Gone summit', 'start_date' => '2026-01-01 10:00:00', 'is_active' => '0'],
    ]);

    $this->assertSame([
      'id' => 999_999_999,
      'title' => 'Gone summit',
      'start_date' => '2026-01-01 10:00:00',
      'is_active' => 0,
    ], $vars['event']);
  }

  public function testPayloadWithoutIdsAddsNothing(): void {
    $this->assertSame([], ContextVars::build(['GroupContact' => ['group_id' => 0], 'Event' => ['title' => 'No id']]));
  }

}
