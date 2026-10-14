<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Digest block from a SearchKit display: herald runs it as the system, like
 * its own digest queries, and hands the formatted rows to a template.
 */
final class SearchBlock {

  /**
   * Display types whose rows are cells by column; the others feed widgets.
   */
  public const TYPES = ['table', 'list', 'grid'];

  /**
   * Why the display of a search block cannot be sent, or NULL.
   *
   * @param array<string, mixed> $block HeraldDigestBlock values
   */
  public static function problem(array $block): ?string {
    $id = Cast::toInt($block['search_display_id'] ?? 0);
    if (($block['type'] ?? NULL) !== 'search' || $id < 1) {
      return NULL;
    }
    $display = self::display($id);
    if ($display === NULL) {
      return E::ts('search display %1 does not exist.', [1 => $id]);
    }
    $type = Cast::toString($display['type']);
    return (
      in_array($type, self::TYPES, TRUE)
        ? NULL
        : E::ts('a display of type "%1" has no rows for a digest; use a table, list or grid.', [1 => $type])
    );
  }

  /**
   * @return array{labels: list<string>, rows: list<array{cells: list<string>, values: array<string, string>}>, count: int}
   */
  public static function vars(int $displayId): array {
    $display = self::display($displayId);
    if ($display === NULL) {
      throw new \CRM_Core_Exception("herald: search display {$displayId} does not exist");
    }
    $search = Cast::toString($display['saved_search_id.name']);
    // A display applies the current user's ACLs unless it bypasses them.
    $display['acl_bypass'] = TRUE;
    /** @var list<array{columns: list<mixed>}> $result */
    $result = \Civi\Api4\SearchDisplay::run(checkPermissions: FALSE)
      ->setSavedSearch($search)
      ->setDisplay($display)
      ->execute()
      ->getArrayCopy();
    $columns = SearchRows::columns($display, $search);
    return [
      'labels' => array_values(array_map(static fn (array $column): string => Cast::toString(
        $column['label'] ?? '',
      ), $columns)),
      'rows' => array_map(static fn (array $row): array => SearchRows::row($row['columns'], $columns), $result),
      'count' => count($result),
    ];
  }

  /**
   * @return array<string, mixed>|null
   */
  private static function display(int $id): ?array {
    $display = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addSelect('*', 'saved_search_id.name')
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
    return is_array($display) ? Cast::namedOnly($display) : NULL;
  }

}
