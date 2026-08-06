<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

/**
 * Which (entity, field) pairs hook_civicrm_pre must snapshot for the generic
 * field-transition condition.
 *
 * The pairs are read from the ACTIVE rules' condition params in the CiviRules
 * tables, cached per request: the pre hook fires on every write and must stay
 * cheap, and rule edits take effect on the next request, which is fine.
 */
final class FieldTransitionWatchList {

  public const ADAPTER_CLASS = 'CRM_Herald_CivirulesAdapter_Condition_FieldTransition';

  /**
   * @var array<string, string[]>|null entity => list of field names */
  private static ?array $cache = NULL;

  /**
   * Field names the condition watches on $entity; [] when none.
   *
   * @return string[]
   */
  public static function fieldsFor(string $entity): array {
    self::$cache ??= self::load();
    return self::$cache[$entity] ?? [];
  }

  /**
   * Test seam / cache reset after rule edits within one process.
   */
  public static function reset(): void {
    self::$cache = NULL;
  }

  /**
   * @return array<string, string[]>
   */
  private static function load(): array {
    if (
      !\CRM_Core_DAO::checkTableExists('civirule_condition')
      || !\CRM_Core_DAO::checkTableExists('civirule_rule_condition')
    ) {
      return [];
    }
    /** @var \CRM_Core_DAO&object{condition_params: ?string} $dao */
    $dao = \CRM_Core_DAO::executeQuery(
      'SELECT rc.condition_params FROM civirule_rule_condition rc
       INNER JOIN civirule_condition c ON c.id = rc.condition_id
       INNER JOIN civirule_rule r ON r.id = rc.rule_id
       WHERE c.class_name = %1 AND r.is_active = 1',
      [1 => [self::ADAPTER_CLASS, 'String']],
    );
    $list = [];
    while ($dao->fetch()) {
      $params = self::decode($dao->condition_params);
      $entity = $params['entity'] ?? NULL;
      $field = $params['field'] ?? NULL;
      if (!is_string($entity) || !is_string($field) || $entity === '' || $field === '') {
        continue;
      }
      if (!in_array($field, $list[$entity] ?? [], TRUE)) {
        $list[$entity][] = $field;
      }
    }
    return $list;
  }

  /**
   * @return array<array-key, mixed>
   */
  private static function decode(?string $raw): array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    $decoded = unserialize($raw, ['allowed_classes' => FALSE]);
    return is_array($decoded) ? $decoded : [];
  }

}
