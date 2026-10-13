<?php

declare(strict_types = 1);

namespace Civi\Herald\Schedule;

use PHPUnit\Framework\TestCase;

/**
 * Slot computation and the fire-once rule. 2030-01-07 is a Monday.
 * @group unit
 */
class ScheduleTest extends TestCase {

  private const ZONE = 'Europe/Berlin';

  /**
   * @return array<string, array{list<int>, int, string, string}>
   */
  public static function slots(): array {
    return [
      'weekly, before the hour on the day' => [[1], 8, '2030-01-14 07:59', '2030-01-07 08:00'],
      'weekly, on the hour' => [[1], 8, '2030-01-14 08:00', '2030-01-14 08:00'],
      'weekly, across the week boundary' => [[1], 8, '2030-01-13 23:30', '2030-01-07 08:00'],
      'Sunday seen from Monday' => [[7], 8, '2030-01-07 10:00', '2030-01-06 08:00'],
      'Monday and Thursday, midweek' => [[1, 4], 8, '2030-01-09 12:00', '2030-01-07 08:00'],
      'Monday and Thursday, on Thursday' => [[4, 1], 8, '2030-01-10 09:00', '2030-01-10 08:00'],
      'Monday and Thursday, on Sunday' => [[1, 4], 8, '2030-01-13 08:00', '2030-01-10 08:00'],
      'every day, before the hour' => [[], 8, '2030-01-09 07:00', '2030-01-08 08:00'],
      'every day, after the hour' => [[], 8, '2030-01-09 09:00', '2030-01-09 08:00'],
      'midnight' => [[], 0, '2030-01-09 00:00', '2030-01-09 00:00'],
      'late hour across the year boundary' => [[2], 23, '2030-01-01 22:00', '2029-12-25 23:00'],
    ];
  }

  /**
   * @dataProvider slots
   * @param list<int> $weekdays
   */
  public function testDueSlot(array $weekdays, int $hour, string $now, string $expected): void {
    $zone = new \DateTimeZone(self::ZONE);
    $slot = ScheduleParams::parse(['weekdays' => $weekdays, 'hour' => $hour])
      ->dueSlot(new \DateTimeImmutable($now, $zone));
    self::assertSame($expected, $slot->format('Y-m-d H:i'));
    self::assertSame(self::ZONE, $slot->getTimezone()->getName());
  }

  public function testAllSevenWeekdaysMeanEveryDay(): void {
    $schedule = ScheduleParams::parse(['weekdays' => ['7', '1', '2', '3', '4', '5', '6', '1'], 'hour' => '8']);
    self::assertSame([], $schedule->weekdays);
    self::assertSame(8, $schedule->hour);
    self::assertSame(0, $schedule->since);
  }

  public function testStoredStringsAreNormalised(): void {
    $schedule = ScheduleParams::parse(['weekdays' => ['4', '1', '4'], 'hour' => '7', 'since' => '1893999600']);
    self::assertSame([1, 4], $schedule->weekdays);
    self::assertSame(7, $schedule->hour);
    self::assertSame(1_893_999_600, $schedule->since);
    self::assertSame([], ScheduleParams::parse(['weekdays' => '', 'hour' => 7])->weekdays);
  }

  public function testFiresOncePerSlot(): void {
    $slot = 1000;
    self::assertTrue(Schedule::fires($slot, NULL, 0), 'never run');
    self::assertTrue(Schedule::fires($slot, $slot - 1, 0), 'last run before the slot');
    self::assertFalse(Schedule::fires($slot, $slot, 0), 'run exactly at the slot');
    self::assertFalse(Schedule::fires($slot, $slot + 300, 0), 'run after the slot');
  }

  public function testNoSlotFromBeforeTheScheduleExisted(): void {
    self::assertFalse(Schedule::fires(1000, NULL, 1001));
    self::assertTrue(Schedule::fires(1000, NULL, 1000));
  }

  /**
   * @return array<string, array{array<string, mixed>, string}>
   */
  public static function invalidParams(): array {
    return [
      'no hour' => [['weekdays' => [1]], 'herald: the schedule has no hour'],
      'empty hour' => [['hour' => ''], 'herald: the schedule has no hour'],
      'hour 24' => [['hour' => 24], 'herald: schedule hour must be an hour from 0 to 23, got 24'],
      'negative hour' => [['hour' => '-1'], "herald: schedule hour must be an hour from 0 to 23, got '-1'"],
      'fractional hour' => [['hour' => '8.5'], "herald: schedule hour must be an hour from 0 to 23, got '8.5'"],
      'boolean hour' => [['hour' => TRUE], 'herald: schedule hour must be an hour from 0 to 23, got true'],
      'weekday 0' => [
        ['hour' => 8, 'weekdays' => [0]],
        'herald: a schedule weekday must be 1 (Monday) to 7 (Sunday), got 0',
      ],
      'weekday 8' => [
        ['hour' => 8, 'weekdays' => ['8']],
        "herald: a schedule weekday must be 1 (Monday) to 7 (Sunday), got '8'",
      ],
      'weekday name' => [
        ['hour' => 8, 'weekdays' => ['Monday']],
        "herald: a schedule weekday must be 1 (Monday) to 7 (Sunday), got 'Monday'",
      ],
      'weekdays not a list' => [['hour' => 8, 'weekdays' => 1], 'herald: schedule weekdays must be a list, got 1'],
      'since not a time' => [
        ['hour' => 8, 'since' => 'yesterday'],
        "herald: schedule since must be a Unix time, got 'yesterday'",
      ],
    ];
  }

  /**
   * @dataProvider invalidParams
   * @param array<string, mixed> $params
   */
  public function testRejectsInvalidParams(array $params, string $message): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage($message);
    ScheduleParams::parse($params);
  }

  public function testHourNamesTheLabelAndTheValue(): void {
    self::assertSame(0, ScheduleParams::hour(0, 'sendHour'));
    self::assertSame(23, ScheduleParams::hour('23', 'sendHour'));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('herald: sendHour must be an hour from 0 to 23, got 25');
    ScheduleParams::hour(25, 'sendHour');
  }

  public function testHourRejectsNonScalars(): void {
    $this->expectExceptionMessage('herald: sendHour must be an hour from 0 to 23, got array');
    ScheduleParams::hour([], 'sendHour');
  }

}
