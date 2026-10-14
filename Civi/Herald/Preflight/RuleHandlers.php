<?php

declare(strict_types = 1);

namespace Civi\Herald\Preflight;

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Herald\Api4\Rows;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The handler classes CiviRules would load, keyed "kind:class", from herald's
 * managed declarations or from rules; loads no class. CiviRules looks up rules only through
 * an active trigger, so a rule whose trigger is missing or inactive loads none.
 */
final class RuleHandlers {

  private const KINDS = [
    'CiviRulesCondition' => 'condition',
    'CiviRulesAction' => 'action',
    'CiviRulesTrigger' => 'trigger',
  ];

  /**
   * @return array<string, array{kind: string, class: string, rule_ids: list<int>, problem: ?string}>
   */
  public static function registered(): array {
    $handlers = [];
    /** @var list<array{entity: string, params: array{values: array{class_name: string}}}> $declarations */
    $declarations = require dirname(__DIR__, 3) . '/managed/CiviRules.mgd.php';
    foreach ($declarations as $declaration) {
      $kind = self::KINDS[$declaration['entity']] ?? NULL;
      $class = $declaration['params']['values']['class_name'];
      if ($kind !== NULL) {
        $handlers[$kind . ':' . $class] = ['kind' => $kind, 'class' => $class, 'rule_ids' => [], 'problem' => NULL];
      }
    }
    return $handlers;
  }

  /**
   * Handlers of the given rules, whether or not they are active.
   *
   * @param list<int> $ruleIds
   * @return array<string, array{kind: string, class: string, rule_ids: list<int>, problem: ?string}>
   */
  public static function ofRules(array $ruleIds): array {
    return $ruleIds === [] ? [] : self::collect('id', 'IN', $ruleIds);
  }

  /**
   * @return array<string, array{kind: string, class: string, rule_ids: list<int>, problem: ?string}>
   */
  public static function active(): array {
    return self::collect('is_active', '=', value: TRUE);
  }

  /**
   * Those of the given rules whose trigger is missing or inactive.
   *
   * @param list<int> $ruleIds
   * @return list<int>
   */
  public static function dormant(array $ruleIds): array {
    return (
      $ruleIds === []
        ? []
        : array_map(
          Cast::toInt(...),
          array_values(
            CiviRulesRule::get(checkPermissions: FALSE)
              ->addSelect('id')
              ->addWhere('id', 'IN', $ruleIds)
              ->addClause('OR', ['trigger_id.is_active', '=', FALSE], ['trigger_id.is_active', 'IS NULL'])
              ->execute()
              ->column('id'),
          ),
        )
    );
  }

  /**
   * @return array<string, array{kind: string, class: string, rule_ids: list<int>, problem: ?string}>
   */
  private static function collect(string $field, string $op, mixed $value): array {
    $handlers = [];
    $rules = CiviRulesRule::get(checkPermissions: FALSE)
      ->addSelect('id', 'trigger_id', 'trigger_id.*')
      ->addWhere($field, $op, $value)
      ->addWhere('trigger_id.is_active', '=', value: TRUE)
      ->execute();
    foreach (Rows::of($rules) as $rule) {
      self::add($handlers, 'trigger', $rule['id'], ...HandlerContract::trigger($rule));
    }
    foreach ([
      'condition' => CiviRulesRuleCondition::get(checkPermissions: FALSE),
      'action' => CiviRulesRuleAction::get(checkPermissions: FALSE),
    ] as $kind => $get) {
      $rows = $get
        ->addSelect('rule_id', $kind . '_id.class_name')
        ->addWhere('is_active', '=', value: TRUE)
        ->addWhere('rule_id.' . $field, $op, $value)
        ->addWhere('rule_id.trigger_id.is_active', '=', value: TRUE)
        ->execute();
      foreach (Rows::of($rows) as $row) {
        self::add($handlers, $kind, $row['rule_id'], Cast::toString($row[$kind . '_id.class_name']));
      }
    }
    return $handlers;
  }

  /**
   * @param array<string, array{kind: string, class: string, rule_ids: list<int>, problem: ?string}> $handlers
   */
  private static function add(
    array &$handlers,
    string $kind,
    mixed $ruleId,
    string $class,
    ?string $problem = NULL,
  ): void {
    $key = $kind . ':' . ($problem ?? $class);
    $handlers[$key] ??= ['kind' => $kind, 'class' => $class, 'rule_ids' => [], 'problem' => $problem];
    $handlers[$key]['rule_ids'] = array_values(array_unique([...$handlers[$key]['rule_ids'], Cast::toInt($ruleId)]));
  }

}
