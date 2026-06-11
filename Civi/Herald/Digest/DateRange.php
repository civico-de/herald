<?php

namespace Civi\Herald\Digest;

/**
 * Inclusive-start, exclusive-end date range used by the stats service.
 *
 * Always carries a label so digest templates can render the period name
 * without recomputing ("Yesterday", "2026-05-06", "last 7 days", …).
 */
class DateRange {

  public function __construct(
    public readonly \DateTimeImmutable $start,
    public readonly \DateTimeImmutable $end,
    public readonly string $label,
  ) {}

  public static function yesterday(): self {
    $now = new \DateTimeImmutable('now');
    $start = $now->modify('-1 day')->setTime(0, 0, 0);
    $end = $now->setTime(0, 0, 0);
    return new self($start, $end, $start->format('Y-m-d'));
  }

  public static function today(): self {
    $start = (new \DateTimeImmutable('now'))->setTime(0, 0, 0);
    $end = $start->modify('+1 day');
    return new self($start, $end, $start->format('Y-m-d'));
  }

  public static function lastDays(int $days): self {
    $now = new \DateTimeImmutable('now');
    $start = $now->modify("-{$days} days")->setTime(0, 0, 0);
    $end = $now->setTime(0, 0, 0);
    return new self($start, $end, sprintf('last %d days', $days));
  }

  public static function lastMinutes(int $minutes): self {
    $end = new \DateTimeImmutable('now');
    $start = $end->modify("-{$minutes} minutes");
    return new self($start, $end, sprintf('last %d min', $minutes));
  }

  public function startSql(): string {
    return $this->start->format('Y-m-d H:i:s');
  }

  public function endSql(): string {
    return $this->end->format('Y-m-d H:i:s');
  }

}
