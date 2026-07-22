<?php

namespace Civi\Herald\Monitor;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for Monitor::computeTransitions.
 * @group unit
 */
class MonitorTest extends TestCase {

  public function testOkToFailAndBackAlert(): void {
    $t = Monitor::computeTransitions(
      ['a' => ['status' => 'ok'], 'b' => ['status' => 'fail']],
      ['a' => ['status' => 'fail', 'detail' => 'boom'], 'b' => ['status' => 'ok']]
    );
    $this->assertSame([
      ['check' => 'a', 'status' => 'fail', 'detail' => 'boom'],
      ['check' => 'b', 'status' => 'ok', 'detail' => ''],
    ], $t);
  }

  public function testSteadyStateStaysSilent(): void {
    $state = ['a' => ['status' => 'fail'], 'b' => ['status' => 'ok']];
    $this->assertSame([], Monitor::computeTransitions($state, $state));
  }

  public function testNewCheckAlertsOnlyWhenFailing(): void {
    $t = Monitor::computeTransitions([], [
      'new_ok' => ['status' => 'ok'],
      'new_fail' => ['status' => 'fail'],
    ]);
    $this->assertSame([['check' => 'new_fail', 'status' => 'fail', 'detail' => '']], $t);
  }

  public function testVanishedCheckIsDropped(): void {
    $this->assertSame([], Monitor::computeTransitions(['gone' => ['status' => 'fail']], []));
  }

}
