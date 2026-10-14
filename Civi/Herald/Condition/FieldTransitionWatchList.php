<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Api4\CiviRulesRuleCondition;
use Civi\Herald\Api4\Rows;

/**
 * Which (entity, field) pairs hook_civicrm_pre must snapshot for the generic
 * field-transition condition.
 *
 * The pairs are read from the ACTIVE rules' condition params in the CiviRules
 * tables, cached per request: the pre hook fires on every write and must stay
 * cheap, and rule edits take effect on the next request, which is fine.
 * Long-running workers (queue consumers, CLI loops) keep the stale list until
 * restart — accepted limitation.
 */
final class FieldTransitionWatchList {

  public const ADAPTER_CLASS = 'CRM_Herald_CivirulesAdapter_Condition_FieldTransition';

  /**
   * hook_civicrm_pre objectNames that mean a Contact row: conditions are
   * configured against the APIv4 entity "Contact".
   */
  private const CONTACT_OBJECT_NAMES = ['individual', 'organization', 'household', 'contact'];

  /**
   * @var array<string, array<int, array{entity: string, field: string}>>|null
   *   normalized entity key => watched pairs (entity spelled as configured) */
  private static ?array $cache = NULL;

  /**
   * Watched (entity, field) pairs for a hook objectName; entity comes back as
   * configured on the condition, because the snapshot key must match what the
   * condition later reads. Matching is case-insensitive (the form takes free
   * text) and folds contact subtypes to Contact.
   *
   * @return array<int, array{entity: string, field: string}>
   */
  public static function watchesFor(string $objectName): array {
    self::$cache ??= self::load();
    return self::$cache[self::normalizeEntity($objectName)] ?? [];
  }

  /**
   * Fold a hook objectName / free-text entity to its match key: lowercase,
   * contact subtypes (Individual/Organization/Household) become "contact".
   */
  public static function normalizeEntity(string $entity): string {
    $key = strtolower(trim($entity));
    return in_array($key, self::CONTACT_OBJECT_NAMES, TRUE) ? 'contact' : $key;
  }

  /**
   * Test seam / cache reset after rule edits within one process.
   */
  public static function reset(): void {
    self::$cache = NULL;
  }

  /**
   * @return array<string, array<int, array{entity: string, field: string}>>
   */
  private static function load(): array {
    // A disabled CiviRules fires no rule, so nothing needs a snapshot.
    if (!\CRM_Herald_CivirulesAdapter_Availability::isInstalled()) {
      return [];
    }
    $conditions = CiviRulesRuleCondition::get(checkPermissions: FALSE)
      ->addSelect('condition_params')
      ->addWhere('condition_id.class_name', '=', self::ADAPTER_CLASS)
      ->addWhere('rule_id.is_active', '=', value: TRUE)
      ->execute();
    $list = [];
    foreach (Rows::of($conditions) as $condition) {
      $params = FieldTransitionParams::decode($condition['condition_params']);
      if ($params['entity'] === '' || $params['field'] === '') {
        continue;
      }
      $key = self::normalizeEntity($params['entity']);
      $pair = ['entity' => $params['entity'], 'field' => $params['field']];
      if (!in_array($pair, $list[$key] ?? [], TRUE)) {
        $list[$key][] = $pair;
      }
    }
    return $list;
  }

}
