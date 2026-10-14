<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * A digest source from a SearchKit display: herald runs it as the system and
 * hands the raw values of its named columns to the template.
 *
 * @phpstan-import-type Field from Snippet
 */
final class SearchSource {

  /**
   * Display types whose rows are cells by column; the others feed widgets.
   */
  public const TYPES = ['table', 'list', 'grid'];

  /**
   * Why the display cannot fill a digest, or NULL.
   */
  public static function problem(int $displayId): ?string {
    $display = DisplayColumns::display($displayId);
    if ($display === NULL) {
      return E::ts('search display %1 does not exist.', [1 => $displayId]);
    }
    $type = Cast::toString($display['type']);
    if (!in_array($type, self::TYPES, TRUE)) {
      return E::ts('a display of type "%1" has no rows for a digest; use a table, list or grid.', [1 => $type]);
    }
    $problems = Placeholders::fromLabels(array_column(DisplayColumns::of($display), 'label'))['problems'];
    return $problems === [] ? NULL : implode(' ', $problems);
  }

  /**
   * The placeholder name, header and data type of each named column in
   * display order, and whether the search yields one row.
   *
   * @return array{fields: list<Field>, single: bool}
   * @throws DigestProblem
   */
  public static function placeholders(int $displayId): array {
    $display = DisplayColumns::existing($displayId);
    $columns = DisplayColumns::of($display);
    $search = SearchShape::of($display);
    $fields = [];
    foreach (Placeholders::fromLabels(array_column($columns, 'label'))['names'] as $index => $name) {
      $fields[] = [
        'name' => $name,
        'label' => $columns[$index]['label'],
        'type' => $search['types'][$columns[$index]['key']] ?? NULL,
      ];
    }
    return ['fields' => $fields, 'single' => $search['single']];
  }

  /**
   * Each named column with its value in the first row (NULL without rows),
   * and every row under `rows`.
   *
   * @return array<string, mixed>
   * @throws DigestProblem
   * @throws \CRM_Core_Exception when the search cannot run
   */
  public static function values(int $displayId): array {
    $display = DisplayColumns::existing($displayId);
    $columns = DisplayColumns::of($display);
    $names = Placeholders::fromLabels(array_column($columns, 'label'));
    if ($names['problems'] !== []) {
      throw new DigestProblem($names['problems']);
    }
    $search = Cast::toString($display['saved_search_id.name']);
    // A display applies the current user's ACLs unless it bypasses them.
    $display['acl_bypass'] = TRUE;
    /** @var list<array<string, mixed>> $result */
    $result = \Civi\Api4\SearchDisplay::run(checkPermissions: FALSE)
      ->setSavedSearch($search)
      ->setDisplay($display)
      ->execute()
      ->getArrayCopy();
    $keys = array_column($columns, 'key');
    $totals = SearchTotals::of($display['saved_search_id.api_params'] ?? NULL);
    $rows = array_map(static fn (array $row): array => self::row(
      (array) ($row['data'] ?? []),
      $keys,
      $names['names'],
      $totals,
    ), $result);
    return ($rows[0] ?? array_fill_keys($names['names'], NULL)) + [Placeholders::ROWS => $rows];
  }

  /**
   * Raw values, as `data` of the run has them; an empty string is a missing value.
   *
   * @param array<array-key, mixed> $data
   * @param array<int, string> $keys Column keys by index
   * @param array<int, string> $names Placeholder names by column index
   * @param array<string, int> $totals
   * @return array<string, mixed>
   */
  private static function row(array $data, array $keys, array $names, array $totals): array {
    $values = [];
    foreach ($names as $index => $name) {
      $value = $data[$keys[$index]] ?? NULL;
      $values[$name] = $value === '' || $value === NULL ? $totals[$keys[$index]] ?? NULL : $value;
    }
    return $values;
  }

}
