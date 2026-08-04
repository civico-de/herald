<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Herald\StatusTransitionDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the contribution status-transition check.
 *
 * @group unit
 */
class ContributionStatusTransitionTest extends TestCase {

  public function testMatchesReturnsTrueOnExactMatch(): void {
    $this->assertTrue(
      ContributionStatusTransition::matches(2, 2),
    );
  }

  public function testMatchesReturnsFalseOnDifferentValue(): void {
    $this->assertFalse(
      ContributionStatusTransition::matches(2, 1),
    );
  }

  public function testMatchesReturnsFalseWhenExpectedMissing(): void {
    $this->assertFalse(
      ContributionStatusTransition::matches(NULL, 2),
    );
  }

  public function testMatchesReturnsFalseWhenSnapshotMissing(): void {
    $this->assertFalse(
      ContributionStatusTransition::matches(2, NULL),
    );
  }

  public function testRoundTripWithDispatcher(): void {
    // Realistic flow: hook_civicrm_pre stashes the snapshot, condition reads it.
    StatusTransitionDispatcher::rememberPreEditStatus(8008, 2);
    $previous = StatusTransitionDispatcher::getPreEditStatus(8008);
    $this->assertTrue(
      ContributionStatusTransition::matches(2, $previous),
    );
    $this->assertFalse(
      ContributionStatusTransition::matches(1, $previous),
    );
  }

}
