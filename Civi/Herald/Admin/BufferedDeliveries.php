<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Waiting and parked alerts are sent with the delivery they were stored
 * with: its channel and the topic whose subscribers it reaches.
 */
final class BufferedDeliveries {

  /**
   * @return list<string> One line naming the count, or none.
   */
  public static function naming(string $key, string $value): array {
    /** @var list<array{delivery: mixed}> $rows */
    $rows = \CRM_Utils_SQL_Select::from('civicrm_herald_buffer')->select('delivery')->execute()->fetchAll();
    $count = 0;
    foreach ($rows as $row) {
      $delivery = json_decode(Cast::toString($row['delivery']), TRUE);
      $count += is_array($delivery) && ($delivery[$key] ?? NULL) === $value ? 1 : 0;
    }
    return $count === 0 ? [] : [E::ts('%1 buffered or parked alerts', [1 => $count])];
  }

}
