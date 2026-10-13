<?php

declare(strict_types = 1);

namespace Civi\Herald\Schedule;

/**
 * A weekly time slot: hour:00 on the chosen ISO weekdays (1 = Monday … 7 = Sunday),
 * every day when none are chosen. ScheduleParams builds it from the trigger params.
 */
final class Schedule {

  /**
   * @param list<int> $weekdays sorted, unique, 1–7; empty means every day
   * @param int $hour 0–23
   * @param int $since Unix time the schedule was saved; no slot before it fires
   */
  public function __construct(
    public readonly array $weekdays,
    public readonly int $hour,
    public readonly int $since,
  ) {}

  /**
   * The most recent moment at or before $now that is hour:00 on a chosen weekday,
   * in $now's timezone.
   */
  public function dueSlot(\DateTimeImmutable $now): \DateTimeImmutable {
    $slot = $now->setTime($this->hour, 0);
    if ($slot > $now) {
      $slot = $slot->modify('-1 day')->setTime($this->hour, 0);
    }
    for ($i = 0; $i < 7 && !$this->runsOn((int) $slot->format('N')); $i++) {
      $slot = $slot->modify('-1 day')->setTime($this->hour, 0);
    }
    return $slot;
  }

  /**
   * A slot fires once: not before the schedule existed, and only while no run
   * was logged at or after it.
   */
  public static function fires(int $slot, ?int $lastRun, int $notBefore): bool {
    return $slot >= $notBefore && ($lastRun === NULL || $lastRun < $slot);
  }

  private function runsOn(int $isoWeekday): bool {
    return $this->weekdays === [] || in_array($isoWeekday, $this->weekdays, TRUE);
  }

}
