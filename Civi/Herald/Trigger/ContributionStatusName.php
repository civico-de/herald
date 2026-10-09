<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Herald\Util\Cast;

/**
 * Contribution status ids as the machine names templates show (2 → "Pending"),
 * read once per request.
 */
final class ContributionStatusName {

  /**
   * Map a contribution_status_id to its machine name (Pending, Completed, …).
   */
  public static function of(mixed $statusId): string {
    if ($statusId === NULL || $statusId === '') {
      return '';
    }
    if (!is_numeric($statusId)) {
      return Cast::toString($statusId);
    }
    /** @var array<int, string>|null $cache */
    static $cache = NULL;
    if ($cache === NULL) {
      $rows = \Civi\Api4\OptionValue::get(checkPermissions: FALSE)
        ->addSelect('value', 'name')
        ->addWhere('option_group_id:name', '=', 'contribution_status')
        ->execute()
        ->getArrayCopy();
      $cache = [];
      foreach ($rows as $row) {
        if (!is_array($row)) {
          continue;
        }
        $cache[Cast::toInt($row['value'] ?? NULL)] = Cast::toString($row['name'] ?? NULL);
      }
    }
    return $cache[(int) $statusId] ?? '';
  }

}
