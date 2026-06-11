<?php

namespace Civi\Herald\Digest;

use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class DateRangeTest extends TestCase {

  public function testYesterdayIsExclusiveEndAtMidnight(): void {
    $r = DateRange::yesterday();
    $expectedStart = (new \DateTimeImmutable('yesterday'))->setTime(0, 0, 0);
    $expectedEnd = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
    $this->assertEquals($expectedStart, $r->start);
    $this->assertEquals($expectedEnd, $r->end);
    $this->assertSame($expectedStart->format('Y-m-d'), $r->label);
  }

  public function testTodayHasMidnightStartAndTomorrowEnd(): void {
    $r = DateRange::today();
    $expectedStart = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
    $expectedEnd = $expectedStart->modify('+1 day');
    $this->assertEquals($expectedStart, $r->start);
    $this->assertEquals($expectedEnd, $r->end);
    $this->assertSame($expectedStart->format('Y-m-d'), $r->label);
  }

  public function testLastDays(): void {
    $r = DateRange::lastDays(7);
    $this->assertSame('last 7 days', $r->label);
    $diff = $r->end->getTimestamp() - $r->start->getTimestamp();
    $this->assertSame(7 * 86400, $diff);
  }

  public function testLastMinutes(): void {
    $r = DateRange::lastMinutes(15);
    $this->assertSame('last 15 min', $r->label);
    $diff = $r->end->getTimestamp() - $r->start->getTimestamp();
    // 15min = 900s, allow 2s clock drift between two `now` calls
    $this->assertEqualsWithDelta(900, $diff, 2);
  }

  public function testSqlFormatting(): void {
    $start = new \DateTimeImmutable('2026-05-06 00:00:00');
    $end = new \DateTimeImmutable('2026-05-07 00:00:00');
    $r = new DateRange($start, $end, '2026-05-06');
    $this->assertSame('2026-05-06 00:00:00', $r->startSql());
    $this->assertSame('2026-05-07 00:00:00', $r->endSql());
  }

}
