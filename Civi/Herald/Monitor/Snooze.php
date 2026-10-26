<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * herald-only muting: check key or prefix ending in `*` => ISO datetime, or
 * '' for a snooze without end.
 */
final class Snooze {

  public const SETTING = 'herald_monitor_snooze';

  /**
   * @param array<string, string> $entries
   */
  public function __construct(
    private readonly array $entries,
    private readonly int $now,
  ) {}

  /**
   * The entries of an admin-edited snooze map that are well-formed and not yet lapsed.
   *
   * @param array<array-key, mixed> $snooze
   * @return array<string, string>
   */
  public static function live(array $snooze, int $now): array {
    $live = [];
    foreach ($snooze as $pattern => $until) {
      if (!is_string($pattern) || !is_string($until) || !KeyPattern::isValid($pattern)) {
        continue;
      }
      if (!self::isLive($until, $now)) {
        continue;
      }
      $live[$pattern] = $until;
    }
    return $live;
  }

  /**
   * Snoozed by any live entry whose pattern matches the key.
   */
  public function covers(string $key): bool {
    foreach ($this->entries as $pattern => $until) {
      if (KeyPattern::score((string) $pattern, $key) !== NULL && self::isLive($until, $this->now)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * '' never ends; an unparseable or past datetime has ended.
   */
  private static function isLive(string $until, int $now): bool {
    if ($until === '') {
      return TRUE;
    }
    $ts = strtotime($until);
    return $ts !== FALSE && $now < $ts;
  }

}
