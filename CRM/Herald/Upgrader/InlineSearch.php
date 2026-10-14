<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Search blocks of older releases ran a SearchKit saved search's own query.
 * A record shows a display, so the migration adds one with the same rows.
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

  /**
   * The block as checked before anything is saved: its search stands in for the display it gets.
   *
   * @param array<string, mixed> $block
   * @return array<string, mixed>
   */
  public static function forCheck(array $block): array {
    if (!array_key_exists('saved_search_id', $block)) {
      return $block;
    }
    $searchId = $block['saved_search_id'];
    unset($block['saved_search_id']);
    return $block + ['search_display_id' => $searchId];
  }

  /**
   * The HeraldDigestBlock values, with a new display for a search block.
   *
   * @param array<string, mixed> $block
   * @return array<string, mixed>
   */
  public static function record(array $block, string $label): array {
    if (!array_key_exists('saved_search_id', $block)) {
      return $block;
    }
    $searchId = Cast::toInt($block['saved_search_id']);
    unset($block['saved_search_id']);
    return $block + ['search_display_id' => self::display($searchId, $label)];
  }

  private static function display(int $searchId, string $label): int {
    $search = \Civi\Api4\SavedSearch::get(checkPermissions: FALSE)
      ->addSelect('name', 'api_params')
      ->addWhere('id', '=', $searchId)
      ->execute()
      ->single();
    $params = is_array($search['api_params'] ?? NULL) ? $search['api_params'] : [];
    $orderBy = is_array($params['orderBy'] ?? NULL) ? $params['orderBy'] : [];
    $default = \Civi\Api4\SearchDisplay::getDefault(checkPermissions: FALSE)
      ->setSavedSearch(Cast::toString($search['name']))
      ->setType('table')
      ->execute()
      ->single();
    // A display's limit and sort replace the search's; 0 is no limit, as in the search.
    $settings =
      [
        'limit' => Cast::toInt($params['limit'] ?? 0),
        'sort' => array_map(NULL, array_keys($orderBy), array_values($orderBy)),
        'pager' => FALSE,
        'actions' => FALSE,
      ] + (is_array($default['settings'] ?? NULL) ? $default['settings'] : []);
    return Cast::toInt(
      \Civi\Api4\SearchDisplay::create(checkPermissions: FALSE)
        ->setValues([
          'saved_search_id' => $searchId,
          'label' => $label,
          'type' => 'table',
          'settings' => $settings,
        ])
        ->execute()
        ->single()['id'],
    );
  }

}
