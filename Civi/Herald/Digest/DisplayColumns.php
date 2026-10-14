<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * A SearchKit display and the columns that carry its values.
 */
final class DisplayColumns {

  /**
   * The display with the name of its search, or NULL.
   *
   * @return array<string, mixed>|null
   */
  public static function display(int $id): ?array {
    $display = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addSelect('*', 'saved_search_id.name', 'saved_search_id.api_params')
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
    return is_array($display) ? Cast::namedOnly($display) : NULL;
  }

  /**
   * @return array<string, mixed>
   * @throws DigestProblem
   */
  public static function existing(int $id): array {
    return self::display($id) ?? throw new DigestProblem([E::ts('search display %1 does not exist.', [1 => $id])]);
  }

  /**
   * Key and header of each value column in display order; links, buttons and menus carry no value.
   * Auto mode takes the columns SearchKit derives from the search, as its run does.
   *
   * @param array<string, mixed> $display
   * @return list<array{key: string, label: string}>
   */
  public static function of(array $display): array {
    $settings = (array) ($display['settings'] ?? []);
    if (($settings['columnMode'] ?? NULL) === 'auto') {
      $default = \Civi\Api4\SearchDisplay::getDefault(checkPermissions: FALSE)
        ->setSavedSearch(Cast::toString($display['saved_search_id.name']))
        ->setType(Cast::toString($display['type']))
        ->execute()
        ->single();
      $settings = (array) ($default['settings'] ?? []);
    }
    $columns = [];
    foreach ((array) ($settings['columns'] ?? []) as $column) {
      if (!is_array($column) || !in_array($column['type'] ?? NULL, ['field', 'html'], TRUE)) {
        continue;
      }
      $columns[] = ['key' => Cast::toString($column['key'] ?? ''), 'label' => Cast::toString($column['label'] ?? '')];
    }
    return $columns;
  }

}
