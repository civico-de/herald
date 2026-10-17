<?php

declare(strict_types = 1);

namespace Civi\Herald\Schedule;

/**
 * When Herald.notifyDigest sends: only during sendHour, or with daily from
 * sendHour on, once a day per digest and delivery settings.
 */
final class SendWindow {

  /**
   * The day each digest last went out daily, by digest name and delivery settings.
   */
  public const SETTING = 'herald_digest_sent_days';

  public const LOCK_NAME = 'worker.herald.digest_daily';

  private const LOCK_WAIT_SECONDS = 10;

  /**
   * The skipped result outside sendHour, or NULL when the digest is due now.
   *
   * @return array<string, mixed>|null
   * @throws \CRM_Core_Exception for an hour outside 0–23
   */
  public static function duringHour(?int $sendHour): ?array {
    if ($sendHour === NULL) {
      return NULL;
    }
    $hour = ScheduleParams::hour($sendHour, 'sendHour');
    $current = (int) Clock::now()->format('G');
    return $current === $hour ? NULL : ['mode' => 'skipped', 'send_hour' => $hour, 'current_hour' => $current];
  }

  /**
   * The skipped result before sendHour or once sent today, or NULL when due. A due
   * digest is marked as sent before it is composed: a failure is not retried that day.
   *
   * @param array<string, mixed> $actionParams
   * @return array<string, mixed>|null
   * @throws \CRM_Core_Exception for an hour outside 0–23
   */
  public static function dailyFromHour(int $sendHour, string $digest, array $actionParams): ?array {
    $hour = ScheduleParams::hour($sendHour, 'sendHour');
    $now = Clock::now();
    $current = (int) $now->format('G');
    if ($current < $hour) {
      return ['mode' => 'skipped', 'send_hour' => $hour, 'current_hour' => $current];
    }
    $today = $now->format('Y-m-d');
    return self::claim($digest, $actionParams, $today) ? NULL : ['mode' => 'skipped', 'sent_on' => $today];
  }

  /**
   * Marks the digest as sent today; FALSE when it already was. Under a lock and
   * read fresh, since an overlapping cron run caches its own settings.
   *
   * @param array<string, mixed> $actionParams
   * @throws \CRM_Core_Exception when another run holds the lock too long
   */
  private static function claim(string $digest, array $actionParams, string $today): bool {
    $lock = \Civi::lockManager()->acquire(self::LOCK_NAME, self::LOCK_WAIT_SECONDS);
    if (!$lock->isAcquired()) {
      throw new \CRM_Core_Exception('herald: another run holds the daily digest lock');
    }
    try {
      \Civi::settings()->loadValues();
      return self::mark(self::key($digest, $actionParams), $today);
    }
    finally {
      $lock->release();
    }
  }

  /**
   * Entries of earlier days are dropped, so the setting holds only today's sends.
   */
  private static function mark(string $key, string $today): bool {
    $stored = \Civi::settings()->get(self::SETTING);
    $days = is_array($stored) ? $stored : [];
    if (($days[$key] ?? NULL) === $today) {
      return FALSE;
    }
    $days = array_filter($days, static fn (mixed $day): bool => $day === $today);
    $days[$key] = $today;
    \Civi::settings()->set(self::SETTING, $days);
    return TRUE;
  }

  /**
   * The digest's name and a hash of its delivery settings, independent of their key order.
   *
   * @param array<string, mixed> $actionParams
   */
  private static function key(string $digest, array $actionParams): string {
    ksort($actionParams);
    return $digest . ':' . hash('xxh128', json_encode($actionParams, JSON_THROW_ON_ERROR));
  }

}
