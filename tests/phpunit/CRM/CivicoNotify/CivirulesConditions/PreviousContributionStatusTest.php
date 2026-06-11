<?php

use Civi\CivicoNotify\StatusTransitionDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for PreviousContributionStatus condition.
 *
 * The CiviRules wrapper itself extends a Civi base class that requires the
 * CiviCRM bootstrap; only the pure match logic + dispatcher integration is
 * tested here.
 *
 * @group unit
 */
class CRM_CivicoNotify_CivirulesConditions_PreviousContributionStatusTest extends TestCase {

  public function testMatchesReturnsTrueOnExactMatch(): void {
    $this->assertTrue(
      CRM_CivicoNotify_CivirulesConditions_PreviousContributionStatus::matches(2, 2)
    );
  }

  public function testMatchesReturnsFalseOnDifferentValue(): void {
    $this->assertFalse(
      CRM_CivicoNotify_CivirulesConditions_PreviousContributionStatus::matches(2, 1)
    );
  }

  public function testMatchesReturnsFalseWhenExpectedMissing(): void {
    $this->assertFalse(
      CRM_CivicoNotify_CivirulesConditions_PreviousContributionStatus::matches(NULL, 2)
    );
  }

  public function testMatchesReturnsFalseWhenSnapshotMissing(): void {
    $this->assertFalse(
      CRM_CivicoNotify_CivirulesConditions_PreviousContributionStatus::matches(2, NULL)
    );
  }

  public function testRoundTripWithDispatcher(): void {
    // Realistic flow: hook_civicrm_pre stashes the snapshot, condition reads it.
    StatusTransitionDispatcher::rememberPreEditStatus(8008, 2);
    $previous = StatusTransitionDispatcher::getPreEditStatus(8008);
    $this->assertTrue(
      CRM_CivicoNotify_CivirulesConditions_PreviousContributionStatus::matches(2, $previous)
    );
    $this->assertFalse(
      CRM_CivicoNotify_CivirulesConditions_PreviousContributionStatus::matches(1, $previous)
    );
  }

}
