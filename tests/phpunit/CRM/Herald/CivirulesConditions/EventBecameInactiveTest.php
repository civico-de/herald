<?php

use Civi\Herald\PreEditSnapshot;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The condition's two misfire guards, exercised against a real row.
 *
 * Both failure modes it exists to prevent are asserted: an unrelated edit
 * while the event is ALREADY inactive must not fire (needs the pre-edit
 * value), and a partial-edit payload without is_active must not be read as
 * "became 0". Current state comes from the live row, previous state from the
 * snapshot with CiviRules' original-data as fallback.
 *
 * @group headless
 */
class CRM_Herald_CivirulesConditions_EventBecameInactiveTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      ->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    PreEditSnapshot::reset();
  }

  private function createEvent(bool $active): int {
    return (int) \Civi\Api4\Event::create(FALSE)
      ->addValue('title', 'Herald test event')
      ->addValue('start_date', '2026-09-01')
      ->addValue('event_type_id', 1)
      ->addValue('is_active', $active)
      ->execute()->first()['id'];
  }

  private function triggerData(?int $eventId): CRM_Civirules_TriggerData_TriggerData {
    $data = new class () extends CRM_Civirules_TriggerData_TriggerData {};
    if ($eventId !== NULL) {
      $data->setEntityData('Event', ['id' => $eventId]);
    }
    return $data;
  }

  private function triggerDataWithOriginal(int $eventId, array $original): CRM_Civirules_TriggerData_TriggerData {
    $data = new class () extends CRM_Civirules_TriggerData_TriggerData implements CRM_Civirules_TriggerData_Interface_OriginalData {

      /**
       * @var array<string, mixed> */
      public array $original = [];

      public function getOriginalData() {
        return $this->original;
      }

      public function getOriginalEntity() {
        return 'Event';
      }

    };
    $data->original = $original;
    $data->setEntityData('Event', ['id' => $eventId]);
    return $data;
  }

  public function testFiresWhenSnapshotSaysItWasActive(): void {
    $id = $this->createEvent(FALSE);
    PreEditSnapshot::remember('Event', $id, 'is_active', 1);
    $condition = new CRM_Herald_CivirulesConditions_EventBecameInactive();
    $this->assertTrue($condition->isConditionValid($this->triggerData($id)));
  }

  public function testDoesNotFireWhileStillActive(): void {
    $id = $this->createEvent(TRUE);
    PreEditSnapshot::remember('Event', $id, 'is_active', 1);
    $condition = new CRM_Herald_CivirulesConditions_EventBecameInactive();
    $this->assertFalse($condition->isConditionValid($this->triggerData($id)));
  }

  /**
   * Already-inactive row edited again, no snapshot, no original data: the
   * previous state is unknown, and a missed alert beats a false one.
   */
  public function testDoesNotFireOnUnknownPreviousState(): void {
    $id = $this->createEvent(FALSE);
    $condition = new CRM_Herald_CivirulesConditions_EventBecameInactive();
    $this->assertFalse($condition->isConditionValid($this->triggerData($id)));
  }

  public function testOriginalDataIsTheFallback(): void {
    $id = $this->createEvent(FALSE);
    $condition = new CRM_Herald_CivirulesConditions_EventBecameInactive();
    $this->assertTrue(
      $condition->isConditionValid($this->triggerDataWithOriginal($id, ['is_active' => 1]))
    );
    $this->assertFalse(
      $condition->isConditionValid($this->triggerDataWithOriginal($id, ['is_active' => 0]))
    );
  }

  public function testSnapshotBeatsOriginalData(): void {
    $id = $this->createEvent(FALSE);
    PreEditSnapshot::remember('Event', $id, 'is_active', 0);
    $condition = new CRM_Herald_CivirulesConditions_EventBecameInactive();
    // Snapshot (was already inactive) wins over a stale original payload.
    $this->assertFalse(
      $condition->isConditionValid($this->triggerDataWithOriginal($id, ['is_active' => 1]))
    );
  }

  public function testNoEventIdIsFalse(): void {
    $condition = new CRM_Herald_CivirulesConditions_EventBecameInactive();
    $this->assertFalse($condition->isConditionValid($this->triggerData(NULL)));
  }

}
