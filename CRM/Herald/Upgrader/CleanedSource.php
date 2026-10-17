<?php

declare(strict_types = 1);

use Civi\Herald\Cleaning\CleanedFields;
use Civi\Herald\Cleaning\Rule;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The contribution-source rules become the cleaned field Contribution.source.
 */
final class CRM_Herald_Upgrader_CleanedSource {

  public const OLD_SETTING = 'herald_source_normalization';

  /**
   * Idempotent: a domain whose new setting already cleans Contribution.source keeps it.
   *
   * @return int The domains whose rules moved
   */
  public static function move(): int {
    /** @var list<array{domain_id: mixed, value: mixed}> $rows */
    $rows = CRM_Utils_SQL_Select::from('civicrm_setting')
      ->select(['domain_id', 'value'])
      ->where('name = @name AND domain_id IS NOT NULL', ['name' => self::OLD_SETTING])
      ->execute()
      ->fetchAll();
    $moved = 0;
    foreach ($rows as $row) {
      $rules = is_string($row['value']) ? CRM_Utils_String::unserialize($row['value']) : NULL;
      $settings = Civi::settings(Cast::toInt($row['domain_id']));
      $entries = $settings->get(CleanedFields::SETTING);
      $entries = is_array($entries) ? $entries : [];
      if (!is_array($rules) || $rules === [] || self::cleansSource($entries)) {
        continue;
      }
      $entries[] = [
        'entity' => 'Contribution',
        'field' => 'source',
        'rules' => array_map(self::rule(...), array_values($rules)),
        'fallback' => '',
      ];
      $settings->set(CleanedFields::SETTING, $entries);
      $moved++;
    }
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_setting WHERE name = %1', [1 => [self::OLD_SETTING, 'String']]);
    return $moved;
  }

  /**
   * @param array<array-key, mixed> $entries
   */
  private static function cleansSource(array $entries): bool {
    foreach ($entries as $entry) {
      if (is_array($entry) && ($entry['entity'] ?? NULL) === 'Contribution' && ($entry['field'] ?? NULL) === 'source') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return array{match: string, type: string, label: string}
   */
  private static function rule(mixed $rule): array {
    $shape = Rule::shape($rule);
    if ($shape['type'] === 'regex') {
      $shape['match'] = CRM_Herald_Upgrader_PhpPattern::toSql($shape['match']);
    }
    return $shape;
  }

}
