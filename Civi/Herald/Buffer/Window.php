<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * How long a delivery collects alerts before it goes out as one message: its
 * own `aggregate_minutes`, where 0 sends at once, else the site's default.
 */
final class Window {

  public const KEY = 'aggregate_minutes';

  public const SETTING = 'herald_aggregate_minutes';

  /**
   * @param array<array-key, mixed> $params Delivery params, a topic's route merged in.
   */
  public static function of(array $params): int {
    $own = $params[self::KEY] ?? NULL;
    if ($own === NULL || $own === '') {
      $own = \Civi::settings()->get(self::SETTING);
    }
    return max(0, Cast::toInt($own));
  }

}
