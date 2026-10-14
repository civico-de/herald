<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use CRM_Herald_ExtensionUtil as E;

/**
 * What the digest form shows beside a source: its placeholders and a snippet
 * of template text that uses them, with money and dates formatted.
 *
 * @phpstan-type Field array{name: string, label: string, type: ?string}
 * @phpstan-type Guide array{placeholders: list<array{placeholder: string, label: string}>, snippet: string}
 * @phpstan-import-type Source from Definition
 */
final class Snippet {

  private const MODIFIERS = ['Money' => '|crmMoney', 'Date' => '|crmDate', 'Timestamp' => '|crmDate'];

  /**
   * @param Source $source
   * @return Guide
   * @throws DigestProblem
   */
  public static function of(array $source, string $digest): array {
    return (
      $source['type'] === 'section'
        ? self::section($source['name'], ExtraSections::answer((string) $source['section_key'], ['digest' => $digest]))
        : self::search($source['name'], SearchSource::placeholders((int) $source['search_display_id']))
    );
  }

  /**
   * A search with one row is read directly, any other is looped over.
   *
   * @param array{fields: list<Field>, single: bool} $search
   * @return Guide
   */
  public static function search(string $name, array $search): array {
    $fields = $search['fields'];
    return [
      'placeholders' => [
        ...self::placeholders($name, $fields),
        [
          'placeholder' => self::variable($name, Placeholders::ROWS),
          'label' => E::ts('Every row, with the names above'),
        ],
      ],
      'snippet' => $search['single']
        ? self::line($name, $fields)
        : self::loop($name . '.' . Placeholders::ROWS, $fields),
    ];
  }

  /**
   * Values are read directly, lists looped over with the names of their first row.
   *
   * @param array<string, mixed> $answer A section as ExtraSections::answer() checked it
   * @return Guide
   */
  public static function section(string $name, array $answer): array {
    $values = array_map(
      self::named(...),
      array_keys(array_filter($answer, static fn (mixed $v): bool => !is_array($v))),
    );
    $placeholders = self::placeholders($name, $values);
    $snippet = $values === [] ? [] : [self::line($name, $values)];
    foreach (array_filter($answer, is_array(...)) as $key => $rows) {
      $first = $rows[0] ?? [];
      $fields = array_map(self::named(...), array_map(strval(...), array_keys(is_array($first) ? $first : [])));
      $placeholders[] = [
        'placeholder' => self::variable($name, $key),
        'label' => $fields === []
          ? E::ts('Rows; there are none now, so their names are unknown')
          : E::ts('Rows with %1', [1 => implode(', ', array_column($fields, 'name'))]),
      ];
      if ($fields !== []) {
        $snippet[] = self::loop("{$name}.{$key}", $fields);
      }
    }
    return ['placeholders' => $placeholders, 'snippet' => implode("\n", $snippet)];
  }

  /**
   * @param list<Field> $fields
   * @return list<array{placeholder: string, label: string}>
   */
  private static function placeholders(string $name, array $fields): array {
    return array_map(
      static fn (array $field): array => [
        'placeholder' => self::variable($name, $field['name']),
        'label' => $field['label'],
      ],
      $fields,
    );
  }

  /**
   * @param list<Field> $fields
   */
  private static function line(string $variable, array $fields): string {
    return implode(', ', array_map(
      // A brace in a header would open a Smarty tag.
      static fn (array $field): string => (
        strtr($field['label'], ['{' => '', '}' => ''])
        . ': {$'
        . $variable
        . '.'
        . $field['name']
        . (self::MODIFIERS[$field['type'] ?? ''] ?? '')
        . '}'
      ),
      $fields,
    ));
  }

  /**
   * @param list<Field> $fields
   */
  private static function loop(string $list, array $fields): string {
    return '{foreach from=$' . $list . " item=row}\n• " . self::line('row', $fields) . "\n{/foreach}";
  }

  private static function variable(string $name, string $key): string {
    return '{$' . $name . '.' . $key . '}';
  }

  /**
   * @return Field
   */
  private static function named(string $name): array {
    return ['name' => $name, 'label' => $name, 'type' => NULL];
  }

}
