<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The settings of the table display a migrated search block gets.
 */
final class CRM_Herald_Upgrader_InlineSearchDisplay {

  /**
   * A table of the search's columns; a display's limit and sort replace the
   * search's, so they are copied over (0 is no limit, as in the search).
   *
   * @param array{name: string, api_params: array<array-key, mixed>} $search
   * @return array<array-key, mixed>
   */
  public static function settings(array $search): array {
    $default = \Civi\Api4\SearchDisplay::getDefault(checkPermissions: FALSE)
      ->setSavedSearch($search['name'])
      ->setType('table')
      ->execute()
      ->single();
    $params = $search['api_params'];
    $orderBy = is_array($params['orderBy'] ?? NULL) ? $params['orderBy'] : [];
    return (
      [
        'limit' => Cast::toInt($params['limit'] ?? 0),
        'sort' => array_map(NULL, array_keys($orderBy), array_values($orderBy)),
        'pager' => FALSE,
        'actions' => FALSE,
      ] + (is_array($default['settings'] ?? NULL) ? $default['settings'] : [])
    );
  }

}
