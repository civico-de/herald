<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Herald\PreEditSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Before-state resolution of Herald.checkFieldTransition: original data
 * first, hook_civicrm_pre snapshot as fallback — and a snapshotted NULL is a
 * KNOWN before-value, so a NULL→"approved" edit can match "from is_empty".
 *
 * @group unit
 */
class CheckFieldTransitionBeforeStateTest extends TestCase {

  protected function setUp(): void {
    parent::setUp();
    PreEditSnapshot::reset();
  }

  public function testOriginalDataWinsOverSnapshot(): void {
    PreEditSnapshot::remember('Contribution', 3, 'status', 'snapshot');
    $state = CheckFieldTransition::resolveBeforeState(
      ['known' => TRUE, 'value' => 'original'],
      'Contribution',
      3,
      'status',
    );
    $this->assertSame(['known' => TRUE, 'value' => 'original'], $state);
  }

  public function testSnapshottedNullCountsAsKnown(): void {
    PreEditSnapshot::remember('Contribution', 3, 'custom.approval', NULL);
    $state = CheckFieldTransition::resolveBeforeState(
      ['known' => FALSE, 'value' => NULL],
      'Contribution',
      3,
      'custom.approval',
    );
    $this->assertSame(['known' => TRUE, 'value' => NULL], $state);
  }

  public function testNoOriginalAndNoSnapshotIsUnknown(): void {
    $state = CheckFieldTransition::resolveBeforeState(['known' => FALSE, 'value' => NULL], 'Contribution', 3, 'status');
    $this->assertSame(['known' => FALSE, 'value' => NULL], $state);
  }

  public function testSnapshotFallbackDeliversTheStoredValue(): void {
    PreEditSnapshot::remember('Event', 9, 'is_active', 1);
    $state = CheckFieldTransition::resolveBeforeState(['known' => FALSE, 'value' => NULL], 'Event', 9, 'is_active');
    $this->assertSame(['known' => TRUE, 'value' => 1], $state);
  }

}
