<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * One run's thresholds, renotify window, snooze map and "now"; pure after construction.
 */
class MonitorConfig {

  public int $openAfter;

  public int $closeAfter;

  public int $renotifySeconds;

  public int $now;

  /**
   * @var array<string, string>
   */
  private array $snooze;

  /**
   * @param array{open_after?: int, close_after?: int, renotify_seconds?: int, snooze?: array<string,string>, now?: int} $config
   */
  public function __construct(array $config = []) {
    $this->openAfter = max(1, (int) ($config['open_after'] ?? 1));
    $this->closeAfter = max(1, (int) ($config['close_after'] ?? 1));
    $this->renotifySeconds = max(0, (int) ($config['renotify_seconds'] ?? 0));
    $this->snooze = $config['snooze'] ?? [];
    $this->now = (int) ($config['now'] ?? 0);
  }

  /**
   * The entries of an admin-edited snooze map that are well-formed and not yet lapsed.
   *
   * @param array<array-key, mixed> $snooze
   * @return array<string, string>
   */
  public static function liveSnooze(array $snooze, int $now): array {
    $live = [];
    foreach ($snooze as $name => $iso) {
      if (!is_string($name) || !is_string($iso)) {
        continue;
      }
      $until = strtotime($iso);
      if ($until === FALSE || $until <= $now) {
        continue;
      }
      $live[$name] = $iso;
    }
    return $live;
  }

  /**
   * Unparseable or past snooze timestamps count as not snoozed.
   */
  public function isSnoozed(string $name): bool {
    if (!isset($this->snooze[$name])) {
      return FALSE;
    }
    $until = strtotime($this->snooze[$name]);
    return $until !== FALSE && $this->now < $until;
  }

}
