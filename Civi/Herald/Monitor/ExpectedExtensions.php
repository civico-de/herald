<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use CRM_Herald_ExtensionUtil as E;

/**
 * A failing pseudo-check for each extension in herald_monitor_expected that
 * is not installed and enabled.
 *
 * @phpstan-import-type ObservationArray from CheckCollector
 */
final class ExpectedExtensions {

  public const SETTING = 'herald_monitor_expected';

  public const PREFIX = 'herald_expected_';

  /**
   * @return array<string, ObservationArray> Observations by pseudo-check key.
   */
  public static function observations(): array {
    $observations = [];
    foreach (self::missing() as $extension) {
      $observations[self::PREFIX . $extension] = [
        'status' => 'fail',
        'severity' => (int) Severity::id('error'),
        'detail' => [],
        'title' => E::ts('Herald: expected extension missing'),
        'text' => E::ts('The extension %1 is not installed and enabled.', [1 => $extension]),
        'visible' => TRUE,
      ];
    }
    return $observations;
  }

  /**
   * @return list<string>
   */
  private static function missing(): array {
    $expected = \Civi::settings()->get(self::SETTING);
    $manager = \CRM_Extension_System::singleton()->getManager();
    $missing = [];
    foreach (is_array($expected) ? $expected : [] as $extension) {
      if (!is_string($extension) || $manager->getStatus($extension) === \CRM_Extension_Manager::STATUS_INSTALLED) {
        continue;
      }
      $missing[] = $extension;
    }
    return $missing;
  }

}
