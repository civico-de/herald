<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * The knobs one monitor run works under, read once and then pure:
 * hysteresis thresholds, the renotify window, the snooze map, and "now".
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
   * Is $name snoozed right now? The snooze map holds ISO datetimes (until
   * when). Unparseable or past timestamps are treated as not snoozed. Pure.
   *
   * @param string $name The check name.
   * @return bool TRUE if the check is silenced.
   */
  public function isSnoozed(string $name): bool {
    if (!isset($this->snooze[$name]) || !is_string($this->snooze[$name])) {
      return FALSE;
    }
    $until = strtotime($this->snooze[$name]);
    return $until !== FALSE && $this->now < $until;
  }

}
