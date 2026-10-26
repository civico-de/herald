<?php

declare(strict_types = 1);

/**
 * The monitor observes System.check by key. State and snoozes of older
 * releases name checks that do not exist there; expected check names map to extension keys.
 */
final class CRM_Herald_Upgrader_MonitorSource {

  /**
   * Check name prefixes of the extensions that delivered monitor checks => their extension key.
   */
  public const KNOWN_PREFIXES = [
    'herald' => 'herald',
    'pulse' => 'pulse',
    'sentinel' => 'sentinel',
    'matomo' => 'matomo',
    'signet' => 'signet',
    'warden' => 'warden',
    'outpost' => 'outpost',
    'shuttle' => 'shuttle',
    'sunset' => 'sunset',
  ];

  /**
   * @return int The expected extension keys written across all domains.
   */
  public static function migrate(): int {
    $written = 0;
    foreach (CRM_Herald_Upgrader_Domain::ids() as $domainId) {
      $settings = Civi::settings($domainId);
      $settings->set('herald_monitor_state', []);
      $settings->set('herald_monitor_snooze', []);
      $expected = self::extensionKeys($settings->get('herald_monitor_expected'));
      $settings->set('herald_monitor_expected', $expected);
      $written += count($expected);
    }
    return $written;
  }

  /**
   * An unknown prefix becomes the key unchanged, so a wrong entry is reported
   * as a missing extension instead of vanishing.
   *
   * @return list<string>
   */
  public static function extensionKeys(mixed $names): array {
    $keys = [];
    foreach (is_array($names) ? $names : [] as $name) {
      if (!is_string($name) || $name === '') {
        continue;
      }
      $prefix = explode('.', $name, 2)[0];
      $keys[] = self::KNOWN_PREFIXES[$prefix] ?? $prefix;
    }
    return array_values(array_unique($keys));
  }

}
