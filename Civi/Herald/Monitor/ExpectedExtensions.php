<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
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
   * Key => title of every extension this site has, and of each expected key
   * it lacks, so the screen can list it.
   *
   * @return array<string, string>
   */
  public static function options(): array {
    $system = \CRM_Extension_System::singleton();
    $keys = $system->getFullContainer()->getKeys();
    $options = [];
    foreach (is_array($keys) ? array_filter($keys, 'is_string') : [] as $key) {
      $label = self::label($system->getMapper(), $key);
      $options[$key] = $label === '' || $label === $key ? $key : sprintf('%s (%s)', $label, $key);
    }
    $expected = \Civi::settings()->get(self::SETTING);
    foreach (is_array($expected) ? array_filter($expected, 'is_string') : [] as $key) {
      $options[$key] ??= E::ts('%1 (not on this site)', [1 => $key]);
    }
    natcasesort($options);
    return $options;
  }

  /**
   * An extension whose info.xml cannot be read is listed by its key, as core's extension list does.
   */
  private static function label(\CRM_Extension_Mapper $mapper, string $key): string {
    try {
      return trim(Cast::toString($mapper->keyToInfo($key)->label));
    }
    catch (\CRM_Extension_Exception) {
      return '';
    }
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
