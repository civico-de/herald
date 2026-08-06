<?php

declare(strict_types = 1);

namespace Civi\Herald;

use PHPUnit\Framework\TestCase;

/**
 * The first-wins contract of the pre-edit store, stated as tests.
 *
 * A single logical operation can write a row several times before CiviRules
 * evaluates; only the FIRST captured value is the genuine "before". These
 * tests pin that semantic so a well-meaning "update the snapshot" change
 * cannot reintroduce the last-wins bug silently.
 *
 * @group unit
 */
class PreEditSnapshotTest extends TestCase {

  protected function setUp(): void {
    parent::setUp();
    PreEditSnapshot::reset();
  }

  public function testFirstValueWins(): void {
    PreEditSnapshot::remember('Event', 7, 'is_active', 1);
    PreEditSnapshot::remember('Event', 7, 'is_active', 0);
    $this->assertSame(1, PreEditSnapshot::get('Event', 7, 'is_active'));
  }

  public function testKeysAreIsolated(): void {
    PreEditSnapshot::remember('Event', 7, 'is_active', 1);
    PreEditSnapshot::remember('Event', 8, 'is_active', 0);
    PreEditSnapshot::remember('Contribution', 7, 'is_active', 'x');
    PreEditSnapshot::remember('Event', 7, 'title', 'Old');
    $this->assertSame(1, PreEditSnapshot::get('Event', 7, 'is_active'));
    $this->assertSame(0, PreEditSnapshot::get('Event', 8, 'is_active'));
    $this->assertSame('x', PreEditSnapshot::get('Contribution', 7, 'is_active'));
    $this->assertSame('Old', PreEditSnapshot::get('Event', 7, 'title'));
  }

  public function testUncapturedReadsAsNull(): void {
    $this->assertNull(PreEditSnapshot::get('Event', 99, 'is_active'));
    $this->assertFalse(PreEditSnapshot::has('Event', 99, 'is_active'));
  }

  /**
   * A snapshotted NULL is a KNOWN before-value — has() must distinguish it
   * from "nothing captured", or NULL→x transitions read as before-unknown.
   */
  public function testRememberedNullIsKnown(): void {
    PreEditSnapshot::remember('Contribution', 5, 'custom.approval', NULL);
    $this->assertTrue(PreEditSnapshot::has('Contribution', 5, 'custom.approval'));
    $this->assertNull(PreEditSnapshot::get('Contribution', 5, 'custom.approval'));
  }

  /**
   * A remembered NULL occupies the slot: a later non-NULL value must not
   * replace it, or "the field really was NULL before" becomes unknowable.
   */
  public function testRememberedNullStillWinsFirst(): void {
    PreEditSnapshot::remember('Event', 7, 'is_active', NULL);
    PreEditSnapshot::remember('Event', 7, 'is_active', 1);
    $this->assertNull(PreEditSnapshot::get('Event', 7, 'is_active'));
  }

  public function testResetClearsTheStore(): void {
    PreEditSnapshot::remember('Event', 7, 'is_active', 1);
    PreEditSnapshot::reset();
    $this->assertNull(PreEditSnapshot::get('Event', 7, 'is_active'));
  }

}
