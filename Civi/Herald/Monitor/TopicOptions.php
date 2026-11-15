<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Topic\Router;
use Civi\Herald\Topic\TopicMeta;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The topics the settings page offers for the check monitor.
 */
final class TopicOptions {

  /**
   * Every configured topic by name; a stored name without an entry of its own
   * stays selectable, so saving the form never changes it unnoticed.
   *
   * @return array<string, string>
   */
  public static function forMonitor(): array {
    $map = \Civi::settings()->get(Router::SETTING);
    $options = [];
    foreach (is_array($map) ? $map : [] as $topic => $entry) {
      $options[(string) $topic] = TopicMeta::label((string) $topic, is_array($entry) ? $entry : []);
    }
    $current = Cast::toString(\Civi::settings()->get(Monitor::TOPIC_SETTING));
    if ($current !== '' && !isset($options[$current])) {
      $options[$current] = Router::exists($current)
        ? E::ts('%1 (through the default topic)', [1 => $current])
        : E::ts('%1 (no such topic)', [1 => $current]);
    }
    return $options;
  }

}
