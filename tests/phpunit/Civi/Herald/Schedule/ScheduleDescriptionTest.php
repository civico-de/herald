<?php

declare(strict_types = 1);

namespace Civi\Herald\Schedule;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The text CiviRules shows for a configured schedule.
 *
 * @group headless
 */
class ScheduleDescriptionTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  /**
   * @return array<string, array{array<string, mixed>, string}>
   */
  public static function descriptions(): array {
    return [
      'no weekday' => [['weekdays' => [], 'hour' => 8], 'Daily at 08:00'],
      'all weekdays' => [['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'hour' => 18], 'Daily at 18:00'],
      'one weekday' => [['weekdays' => [1], 'hour' => 8], 'Every Monday at 08:00'],
      'two weekdays' => [['weekdays' => [4, 1], 'hour' => 0], 'Every Monday and Thursday at 00:00'],
      'three weekdays' => [['weekdays' => [1, 3, 5], 'hour' => 7], 'Every Monday, Wednesday and Friday at 07:00'],
      'Sunday' => [['weekdays' => [7], 'hour' => 23], 'Every Sunday at 23:00'],
    ];
  }

  /**
   * @dataProvider descriptions
   * @param array<string, mixed> $params
   */
  public function testDescription(array $params, string $expected): void {
    self::assertSame($expected, ScheduleText::summary($params));
  }

  public function testUnconfiguredParamsSayWhy(): void {
    self::assertSame('Not configured: herald: the schedule has no hour', ScheduleText::summary([]));
  }

  public function testWeekdayNamesAreIsoNumberedMondayFirst(): void {
    self::assertSame(
      [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
      ScheduleText::weekdayNames(),
    );
  }

  public function testClockFollowsCrmUtilsTime(): void {
    \CRM_Utils_Time::setTime('2030-01-07 08:00:00');
    try {
      self::assertEqualsWithDelta(strtotime('2030-01-07 08:00:00'), Clock::now()->getTimestamp(), 5);
      self::assertSame(Clock::zone()->getName(), Clock::now()->getTimezone()->getName());
    }
    finally {
      \CRM_Utils_Time::resetTime();
    }
  }

}
