<?php

declare(strict_types = 1);

namespace Civi\Herald\Cleaning;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The fields herald cleans (setting herald_cleaned_fields): each text field
 * named here gets a calculated twin, herald_clean_<field>, that SearchKit can
 * group by and notifications show.
 *
 * @phpstan-import-type RuleShape from Rule
 * @phpstan-type CleanedField array{entity: string, field: string, rules: list<RuleShape>, fallback: string}
 */
final class CleanedFields {

  public const SETTING = 'herald_cleaned_fields';

  public const PREFIX = 'herald_clean_';

  /**
   * The stored entries; Setting.set skips the admin screen's checks, so an
   * entry or rule that fails them is left out with a warning.
   *
   * @return list<CleanedField>
   */
  public static function all(): array {
    $configured = \Civi::settings()->get(self::SETTING);
    $entries = [];
    foreach (is_array($configured) ? array_values($configured) : [] as $i => $entry) {
      $entry = is_array($entry) ? $entry : [];
      if (
        !is_string($entry['entity'] ?? NULL)
        || !is_string($entry['field'] ?? NULL)
        || !is_array($entry['rules'] ?? NULL)
      ) {
        \Civi::log('herald')->warning('herald: cleaned field {entry} left out', ['entry' => $i + 1]);
        continue;
      }
      $entries[] = [
        'entity' => $entry['entity'],
        'field' => $entry['field'],
        'rules' => self::rules($entry['field'], $entry['rules']),
        'fallback' => Cast::toString($entry['fallback'] ?? ''),
      ];
    }
    return $entries;
  }

  /**
   * @return list<CleanedField>
   */
  public static function forEntity(string $entity): array {
    return array_values(array_filter(self::all(), static fn (array $entry): bool => $entry['entity'] === $entity));
  }

  public static function name(string $field): string {
    return self::PREFIX . $field;
  }

  /**
   * @param array<array-key, mixed> $rules
   * @return list<RuleShape>
   */
  private static function rules(string $field, array $rules): array {
    $clean = [];
    foreach (array_values($rules) as $i => $rule) {
      $problems = Rule::problemsOf($rule);
      if ($problems !== []) {
        \Civi::log('herald')->warning('herald: cleaning rule {rule} of {field} left out', [
          'rule' => $i + 1,
          'field' => $field,
          'problems' => $problems,
        ]);
        continue;
      }
      $clean[] = Rule::shape($rule);
    }
    return $clean;
  }

}
