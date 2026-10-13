<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Herald;
use Civi\Herald\Schedule\Clock;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Herald.checkSchedule against a rule's own run log. Times are local wall
 * time in CiviCRM's timezone; 2030-01-07 is a Monday.
 *
 * @group headless
 */
class CheckScheduleTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private const MONDAY_8 = ['weekdays' => [1], 'hour' => 8];

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function tearDown(): void {
    \CRM_Utils_Time::resetTime();
    parent::tearDown();
  }

  public function testFiresOncePerSlot(): void {
    $rule = $this->rule('2029-12-01 00:00');
    self::assertTrue($this->due($rule, '2030-01-07 08:05'));
    $this->logRun($rule, '2030-01-07 08:05');
    self::assertFalse($this->due($rule, '2030-01-07 08:20'));
    self::assertFalse($this->due($rule, '2030-01-08 08:05'), 'Tuesday is not a slot');
    self::assertTrue($this->due($rule, '2030-01-14 08:00'), 'next Monday is');
  }

  public function testReportsTheSlotAndTheLastRun(): void {
    $rule = $this->rule('2029-12-01 00:00');
    $this->logRun($rule, '2030-01-07 08:05');
    $result = $this->check($rule, self::MONDAY_8, '2030-01-09 12:00');
    self::assertFalse($result['due']);
    self::assertSame($this->atom('2030-01-07 08:00'), $result['slot']);
    self::assertSame($this->atom('2030-01-07 08:05'), $result['last_run']);
    self::assertSame($this->atom('2029-12-01 00:00'), $result['not_before']);
  }

  public function testCatchesUpAfterAMissedHour(): void {
    $rule = $this->rule('2029-12-01 00:00');
    $this->logRun($rule, '2029-12-31 08:10');
    // Cron ran at 7:45 and next at 9:00.
    self::assertFalse($this->due($rule, '2030-01-07 07:45'));
    self::assertTrue($this->due($rule, '2030-01-07 09:00'));
  }

  public function testSendsOnlyTheLatestSlotNeverABacklog(): void {
    $rule = $this->rule('2029-11-01 00:00');
    $this->logRun($rule, '2029-11-05 08:01');
    // Three Mondays went by without a run; one send covers them.
    self::assertTrue($this->due($rule, '2030-01-07 08:30'));
    $this->logRun($rule, '2030-01-07 08:30');
    self::assertFalse($this->due($rule, '2030-01-07 08:45'));
    self::assertFalse($this->due($rule, '2030-01-13 23:59'));
  }

  public function testNoFireForASlotBeforeTheRuleExisted(): void {
    $rule = $this->rule('2030-01-07 08:30');
    self::assertFalse($this->due($rule, '2030-01-07 08:45'));
    self::assertFalse($this->due($rule, '2030-01-10 12:00'));
    self::assertTrue($this->due($rule, '2030-01-14 08:00'));
  }

  public function testNoFireForASlotBeforeTheScheduleWasSaved(): void {
    $rule = $this->rule('2029-12-01 00:00');
    $this->logRun($rule, '2029-12-30 08:00');
    $schedule = ['since' => $this->time('2030-01-07 08:30')] + self::MONDAY_8;
    self::assertFalse($this->check($rule, $schedule, '2030-01-07 08:45')['due']);
    self::assertTrue($this->check($rule, $schedule, '2030-01-14 08:00')['due']);
  }

  public function testUnknownRuleIsAnError(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('herald: CiviRules rule 999999 does not exist');
    $this->check(999_999, self::MONDAY_8, '2030-01-07 08:05');
  }

  public function testInvalidScheduleIsAnError(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('herald: schedule hour must be an hour from 0 to 23, got 25');
    $this->check($this->rule('2029-12-01 00:00'), ['hour' => 25], '2030-01-07 08:05');
  }

  private function due(int $rule, string $now): bool {
    return $this->check($rule, self::MONDAY_8, $now)['due'] === TRUE;
  }

  /**
   * @param array<string, mixed> $schedule
   * @return array<array-key, mixed>
   */
  private function check(int $rule, array $schedule, string $now): array {
    \CRM_Utils_Time::setTime($this->time($now));
    return (array) Herald::checkSchedule(checkPermissions: FALSE)
      ->setRuleId($rule)
      ->setSchedule($schedule)
      ->execute()
      ->single();
  }

  private function rule(string $created): int {
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule (name, label, trigger_id, is_active, created_date) VALUES (%1, %1, NULL, 1, %2)',
      [1 => ['herald_test_schedule_' . uniqid(), 'String'], 2 => [$this->stored($created), 'String']],
    );
    return (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  private function logRun(int $rule, string $when): void {
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_log (rule_id, log_date) VALUES (%1, %2)',
      [1 => [$rule, 'Integer'], 2 => [$this->stored($when), 'String']],
    );
  }

  /**
   * A moment as CiviRules stores it: PHP's wall time.
   */
  private function stored(string $local): string {
    return date('Y-m-d H:i:s', $this->time($local));
  }

  private function time(string $local): int {
    return (new \DateTimeImmutable($local, Clock::zone()))->getTimestamp();
  }

  private function atom(string $local): string {
    return (new \DateTimeImmutable($local, Clock::zone()))->format(\DATE_ATOM);
  }

}
