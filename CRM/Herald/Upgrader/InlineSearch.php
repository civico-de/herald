<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Search blocks of older releases named a SearchKit saved search and ran its own query.
 * A record shows a display, so the migration adds one with the same rows.
 */
final class CRM_Herald_Upgrader_InlineSearch {

  /**
   * Why the saved search of an old search block cannot become a display, or NULL.
   *
   * @param array<string, mixed> $block
   */
  public static function problem(array $block): ?string {
    if (!array_key_exists('saved_search', $block)) {
      return NULL;
    }
    $name = Cast::toString($block['saved_search']);
    $search = self::search($name);
    if ($search === NULL) {
      return "saved search \"{$name}\" does not exist";
    }
    try {
      self::settings($search);
    }
    catch (CRM_Core_Exception $e) {
      return "saved search \"{$name}\" has no default display: {$e->getMessage()}";
    }
    return NULL;
  }

  /**
   * The block as checked before anything is saved: its search stands in for the display it gets.
   *
   * @param array<string, mixed> $block
   * @return array<string, mixed>
   */
  public static function forCheck(array $block): array {
    if (!array_key_exists('saved_search', $block)) {
      return $block;
    }
    $search = self::search(Cast::toString($block['saved_search']));
    unset($block['saved_search']);
    return $block + ['search_display_id' => $search['id'] ?? NULL];
  }

  /**
   * The HeraldDigestBlock values, with a new display for a search block.
   *
   * @param array<string, mixed> $block
   * @return array<string, mixed>
   */
  public static function record(array $block, string $label): array {
    if (!array_key_exists('saved_search', $block)) {
      return $block;
    }
    $search = self::search(Cast::toString($block['saved_search'])) ?? throw new CRM_Core_Exception(
      'herald: the saved search of a checked block is gone',
    );
    unset($block['saved_search']);
    $id = \Civi\Api4\SearchDisplay::create(checkPermissions: FALSE)
      ->setValues([
        'saved_search_id' => $search['id'],
        // The column holds 255 characters; a job name can fill them alone.
        'label' => mb_substr($label, 0, 255),
        'type' => 'table',
        'settings' => self::settings($search),
      ])
      ->execute()
      ->single()['id'];
    return $block + ['search_display_id' => Cast::toInt($id)];
  }

  /**
   * @return array{id: int, name: string, api_params: array<array-key, mixed>}|null
   */
  private static function search(string $name): ?array {
    $search = \Civi\Api4\SavedSearch::get(checkPermissions: FALSE)
      ->addSelect('id', 'name', 'api_params')
      ->addWhere('name', '=', $name)
      ->execute()
      ->first();
    return (
      is_array($search)
        ? [
          'id' => Cast::toInt($search['id']),
          'name' => Cast::toString($search['name']),
          'api_params' => is_array($search['api_params'] ?? NULL) ? $search['api_params'] : [],
        ] : NULL
    );
  }

  /**
   * A table of the search's columns; a display's limit and sort replace the
   * search's, so they are copied over (0 is no limit, as in the search).
   *
   * @param array{name: string, api_params: array<array-key, mixed>} $search
   * @return array<array-key, mixed>
   */
  private static function settings(array $search): array {
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
