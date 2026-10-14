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
    // A display runs from the first row unless it pages.
    $offset = Cast::toInt($search['api_params']['offset'] ?? 0);
    if ($offset > 0) {
      return "saved search \"{$name}\" skips its first {$offset} rows, which a display cannot";
    }
    try {
      CRM_Herald_Upgrader_InlineSearchDisplay::settings($search);
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
        'settings' => CRM_Herald_Upgrader_InlineSearchDisplay::settings($search),
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

}
