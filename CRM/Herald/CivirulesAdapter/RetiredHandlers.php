<?php

declare(strict_types = 1);

/**
 * Rules that still point at a retired special-case handler move onto the
 * generic one that expresses the same thing; CiviRules resolves handlers from
 * civirule_action/condition rows, so this is a data migration.
 */
final class CRM_Herald_CivirulesAdapter_RetiredHandlers {

  private const NOTIFICATION_CLASS = 'CRM_Herald_CivirulesAdapter_Action_Notification';

  private const FIELD_TRANSITION_CLASS = 'CRM_Herald_CivirulesAdapter_Condition_FieldTransition';

  /**
   * Retired contribution-alert handlers, folded into the generic notification;
   * the campaign now comes from the contribution itself.
   */
  private const RETIRED_ACTIONS = [
    'CRM_Herald_CivirulesActions_SendContributionAlert',
    'CRM_Herald_CivirulesAdapter_Action_ContributionAlert',
  ];

  private const EVENT_BECAME_INACTIVE = [
    'entity' => 'Event',
    'field' => 'is_active',
    'from_operator' => '=',
    'from_value' => '1',
    'to_operator' => '=',
    'to_value' => '0',
  ];

  /**
   * APIv4 entities of CiviRules per handler kind; their params fields are PHP-serialized.
   */
  private const ENTITIES = [
    'action' => ['handler' => 'CiviRulesAction', 'rule' => 'CiviRulesRuleAction'],
    'condition' => ['handler' => 'CiviRulesCondition', 'rule' => 'CiviRulesRuleCondition'],
  ];

  private const RETIRED_ACTION_PARAMS = ['campaign_id', 'goal', 'campaign_title', 'include_status_transition'];

  /**
   * Retired special-case conditions → the field transition each one expresses.
   */
  private const RETIRED_CONDITIONS = [
    'CRM_Herald_CivirulesConditions_PreviousContributionStatus' => 'previousContributionStatus',
    'CRM_Herald_CivirulesAdapter_Condition_PreviousContributionStatus' => 'previousContributionStatus',
    'CRM_Herald_CivirulesConditions_EventBecameInactive' => 'eventBecameInactive',
    'CRM_Herald_CivirulesAdapter_Condition_EventBecameInactive' => 'eventBecameInactive',
  ];

  /**
   * Moves rules off the retired handlers, then deletes the retired handler
   * rows. Needs the Registrar to have registered the generic handlers.
   *
   * @return int Number of rule actions and conditions rewritten.
   */
  public static function migrate(): int {
    if (!\CRM_Core_DAO::checkTableExists('civirule_rule_action')) {
      return 0;
    }
    $migrated = self::moveRuleRows(
      'action',
      array_fill_keys(self::RETIRED_ACTIONS, 'withoutCampaignParams'),
      self::NOTIFICATION_CLASS,
    );
    $migrated += self::moveRuleRows('condition', self::RETIRED_CONDITIONS, self::FIELD_TRANSITION_CLASS);
    \Civi\Herald\Condition\FieldTransitionWatchList::reset();
    return $migrated;
  }

  /**
   * @param 'action'|'condition' $kind
   * @param array<string, string> $retired class name => params transform method
   */
  private static function moveRuleRows(string $kind, array $retired, string $targetClass): int {
    $entities = self::ENTITIES[$kind];
    $moved = 0;
    foreach ($retired as $class => $transform) {
      $handlerId = self::handlerId($entities['handler'], $class);
      if ($handlerId <= 0) {
        continue;
      }
      $targetId = self::handlerId($entities['handler'], $targetClass);
      if ($targetId <= 0) {
        throw new \CRM_Core_Exception("herald: {$targetClass} is not registered as a CiviRules {$kind}");
      }
      $rows = civicrm_api4($entities['rule'], 'get', [
        'select' => ['id', "{$kind}_params"],
        'where' => [["{$kind}_id", '=', $handlerId]],
        'checkPermissions' => FALSE,
      ]);
      foreach ($rows as $row) {
        $params = $row["{$kind}_params"] ?? [];
        civicrm_api4($entities['rule'], 'update', [
          'values' => [
            "{$kind}_id" => $targetId,
            "{$kind}_params" => self::transform($transform, is_array($params) ? $params : [], (int) $row['id']),
          ],
          'where' => [['id', '=', $row['id']]],
          'checkPermissions' => FALSE,
        ]);
        $moved++;
      }
      civicrm_api4($entities['handler'], 'delete', [
        'where' => [['id', '=', $handlerId]],
        'checkPermissions' => FALSE,
      ]);
    }
    return $moved;
  }

  private static function handlerId(string $entity, string $class): int {
    $row = civicrm_api4($entity, 'get', [
      'select' => ['id'],
      'where' => [['class_name', '=', $class]],
      'checkPermissions' => FALSE,
    ])->first();
    return (int) ($row['id'] ?? 0);
  }

  /**
   * @param array<array-key, mixed> $params
   * @return array<array-key, mixed>
   */
  private static function transform(string $transform, array $params, int $ruleRowId): array {
    return match ($transform) {
      'withoutCampaignParams' => self::withoutCampaignParams($params, $ruleRowId),
      'previousContributionStatus' => self::previousContributionStatus($params),
      'eventBecameInactive' => self::EVENT_BECAME_INACTIVE,
      default => throw new \CRM_Core_Exception("herald: unknown rule migration {$transform}"),
    };
  }

  /**
   * Campaign progress now comes from the contribution's campaign; the log keeps
   * what an admin had configured so a goal missing on the Campaign can be restored.
   *
   * @param array<array-key, mixed> $params
   * @return array<array-key, mixed>
   */
  private static function withoutCampaignParams(array $params, int $ruleActionId): array {
    $dropped = array_intersect_key($params, array_flip(self::RETIRED_ACTION_PARAMS));
    if ($dropped !== []) {
      \Civi::log()->warning(
        'herald: contribution alert migrated, campaign params dropped',
        ['rule_action_id' => $ruleActionId] + $dropped,
      );
    }
    return array_diff_key($params, $dropped);
  }

  /**
   * "Previous status was X", whether or not the edit changed it: the after side
   * only makes the transition two-sided. Without a configured status the old condition never matched.
   *
   * @param array<array-key, mixed> $params
   * @return array<string, string>
   */
  private static function previousContributionStatus(array $params): array {
    $previous = $params['previous_status_id'] ?? NULL;
    $configured = is_scalar($previous) && (string) $previous !== '';
    return [
      'entity' => 'Contribution',
      'field' => 'contribution_status_id',
      'from_operator' => $configured ? '=' : '',
      'from_value' => $configured ? (string) $previous : '',
      'to_operator' => $configured ? 'not_empty' : '',
      'to_value' => '',
    ];
  }

}
