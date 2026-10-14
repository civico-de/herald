<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Channel\PlainText;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The columns and rows a search block hands to its template, from a display's run.
 */
final class SearchRows {

  /**
   * The display's value columns by position; links, buttons and menus carry no value.
   * Auto mode takes the columns SearchKit derives from the search, as its run does.
   *
   * @param array<string, mixed> $display
   * @return array<int, array<array-key, mixed>>
   */
  public static function columns(array $display, string $search): array {
    $settings = (array) ($display['settings'] ?? []);
    if (($settings['columnMode'] ?? NULL) === 'auto') {
      $default = \Civi\Api4\SearchDisplay::getDefault(checkPermissions: FALSE)
        ->setSavedSearch($search)
        ->setType(Cast::toString($display['type']))
        ->execute()
        ->single();
      $settings = (array) ($default['settings'] ?? []);
    }
    // Run formats the columns in their order, whatever their keys.
    return array_filter(
      array_values((array) ($settings['columns'] ?? [])),
      static fn (mixed $column): bool => (
        is_array($column) && in_array($column['type'] ?? NULL, ['field', 'html'], TRUE)
      ),
    );
  }

  /**
   * Cells keep the display's column order; values are keyed by the column
   * with non-word characters as "_" (financial_type_id:label → financial_type_id_label).
   *
   * @param list<mixed> $formatted
   * @param array<int, array<array-key, mixed>> $columns
   * @return array{cells: list<string>, values: array<string, string>}
   */
  public static function row(array $formatted, array $columns): array {
    $cells = [];
    $values = [];
    foreach ($columns as $index => $column) {
      $value = ((array) ($formatted[$index] ?? []))['val'] ?? NULL;
      $text = is_array($value) ? implode(', ', array_map(Cast::toString(...), $value)) : Cast::toString($value);
      // Template variables are escaped, so markup of an HTML column would show as tags.
      $text = ($column['type'] ?? NULL) === 'html' ? PlainText::fromHtml($text) : $text;
      $cells[] = $text;
      $values[(string) preg_replace('/\W/', '_', Cast::toString($column['key'] ?? ''))] ??= $text;
    }
    return ['cells' => $cells, 'values' => $values];
  }

}
