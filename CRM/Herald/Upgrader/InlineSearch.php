<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Search blocks of older releases named a SearchKit saved search.
 */
final class CRM_Herald_Upgrader_InlineSearch {

  public static function id(string $name): ?int {
    $id =
      \Civi\Api4\SavedSearch::get(checkPermissions: FALSE)
        ->addSelect('id')
        ->addWhere('name', '=', $name)
        ->execute()
        ->first()['id'] ?? NULL;
    return $id === NULL ? NULL : Cast::toInt($id);
  }

}
