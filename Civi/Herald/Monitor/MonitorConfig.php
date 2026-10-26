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

  private Snooze $snooze;

  /**
   * @param array{open_after?: int, close_after?: int, renotify_seconds?: int, snooze?: array<string,string>, now?: int} $config
   */
  public function __construct(array $config = []) {
    $this->openAfter = max(1, (int) ($config['open_after'] ?? 1));
    $this->closeAfter = max(1, (int) ($config['close_after'] ?? 1));
    $this->renotifySeconds = max(0, (int) ($config['renotify_seconds'] ?? 0));
    $this->now = (int) ($config['now'] ?? 0);
    $this->snooze = new Snooze($config['snooze'] ?? [], $this->now);
  }

  /**
   * Muted while core hides the message or herald snoozes its key.
   */
  public function mutes(Observation $obs): bool {
    return !$obs->visible || $this->snooze->covers($obs->key);
  }

  public function renotifyDue(?int $lastNotified): bool {
    return (
      $this->renotifySeconds > 0
      && $lastNotified !== NULL
      && ($this->now - $lastNotified) >= $this->renotifySeconds
    );
  }

}
