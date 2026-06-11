<?php

namespace Civi\CivicoNotify;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for StatusTransitionDispatcher.
 *
 * The dispatcher holds an in-process snapshot keyed by contribution ID. Tests
 * use disjoint IDs so static state from one test cannot leak into another.
 *
 * @group unit
 */
class StatusTransitionDispatcherTest extends TestCase {

  public function testRememberAndRead(): void {
    StatusTransitionDispatcher::rememberPreEditStatus(1001, 2);
    $this->assertSame(2, StatusTransitionDispatcher::getPreEditStatus(1001));
  }

  public function testGetReturnsNullForUnknownId(): void {
    $this->assertNull(StatusTransitionDispatcher::getPreEditStatus(2002));
  }

  public function testNullStatusIsNotStored(): void {
    StatusTransitionDispatcher::rememberPreEditStatus(3003, NULL);
    $this->assertNull(StatusTransitionDispatcher::getPreEditStatus(3003));
  }

  public function testIdsAreIsolated(): void {
    StatusTransitionDispatcher::rememberPreEditStatus(4004, 1);
    StatusTransitionDispatcher::rememberPreEditStatus(5005, 7);
    $this->assertSame(1, StatusTransitionDispatcher::getPreEditStatus(4004));
    $this->assertSame(7, StatusTransitionDispatcher::getPreEditStatus(5005));
  }

  public function testLastValueWins(): void {
    StatusTransitionDispatcher::rememberPreEditStatus(6006, 2);
    StatusTransitionDispatcher::rememberPreEditStatus(6006, 1);
    $this->assertSame(1, StatusTransitionDispatcher::getPreEditStatus(6006));
  }

  public function testHandlePostHookIsHarmlessNoop(): void {
    $ref = (object) ['contribution_status_id' => 1];
    StatusTransitionDispatcher::handlePostHook(7007, $ref);
    $this->assertNull(StatusTransitionDispatcher::getPreEditStatus(7007));
  }

}
