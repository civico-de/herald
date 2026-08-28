<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Buffer\DeadLetter;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The checks herald contributes about its own delivery pipeline.
 *
 * The distinction under test is what made the original single check useless in
 * production: a parked group stays in the buffer table on purpose, so counting
 * it as backlog left "flush job not delivering" standing forever, long after
 * the flush job had given up on it and moved on.
 *
 * @group headless
 */
class OwnChecksTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const GROUP = 'herald-test-ownchecks';

  private AlertBuffer $buffer;

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_herald_buffer');
    $this->buffer = new AlertBuffer();
  }

  /**
   * @return array<string, array{status: string, detail?: string}>
   */
  private function runChecks(): array {
    $checks = [];
    herald_civicrm_heraldChecks($checks);
    $out = [];
    foreach ($checks as $name => $callable) {
      $out[$name] = $callable();
    }
    return $out;
  }

  private function bufferOverdue(): int {
    $this->buffer->add(self::GROUP, 5, 'alert body', ['channel' => 'telegram']);
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_herald_buffer SET created_date = DATE_SUB(NOW(), INTERVAL 60 MINUTE) WHERE group_hash = %1',
      [1 => [self::GROUP, 'String']],
    );
    return (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_herald_buffer');
  }

  public function testBothChecksPassOnAnEmptyBuffer(): void {
    $checks = $this->runChecks();
    $this->assertSame('ok', $checks['herald.buffer_backlog']['status']);
    $this->assertSame('ok', $checks['herald.buffer_dead_letter']['status']);
  }

  public function testFreshAlertInsideItsWindowIsNoBacklog(): void {
    $this->buffer->add(self::GROUP, 5, 'alert body', ['channel' => 'telegram']);
    $this->assertSame('ok', $this->runChecks()['herald.buffer_backlog']['status']);
  }

  public function testOverdueAlertFailsTheBacklogCheck(): void {
    $this->bufferOverdue();
    $checks = $this->runChecks();
    $this->assertSame('fail', $checks['herald.buffer_backlog']['status']);
    $this->assertStringContainsString('flush job not delivering', $checks['herald.buffer_backlog']['detail']);
    $this->assertSame('ok', $checks['herald.buffer_dead_letter']['status']);
  }

  public function testParkedAlertMovesFromBacklogToDeadLetter(): void {
    $id = $this->bufferOverdue();
    (new DeadLetter($this->buffer))->park([$id], 'no_recipients', 'nobody resolved');

    $checks = $this->runChecks();
    $this->assertSame('ok', $checks['herald.buffer_backlog']['status'], 'a parked row is not a backlog');
    $this->assertSame('fail', $checks['herald.buffer_dead_letter']['status']);
    $this->assertStringContainsString('Herald.getParkedAlerts', $checks['herald.buffer_dead_letter']['detail']);
  }

  public function testReactivatingAParkedAlertPutsItBackIntoTheBacklog(): void {
    $id = $this->bufferOverdue();
    $deadLetter = new DeadLetter($this->buffer);
    $deadLetter->park([$id], 'max_attempts', 'channel down');
    $deadLetter->reactivate([$id]);

    $checks = $this->runChecks();
    $this->assertSame('fail', $checks['herald.buffer_backlog']['status']);
    $this->assertSame('ok', $checks['herald.buffer_dead_letter']['status']);
  }

}
