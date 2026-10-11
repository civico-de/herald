<?php

declare(strict_types = 1);

/**
 * Rules that still point at a retired special-case handler move onto the
 * generic one that expresses the same thing; CiviRules resolves handlers from
 * civirule_action/condition rows, so this is a data migration.
 */
final class CRM_Herald_CivirulesAdapter_RetiredHandlers {

  private const NOTIFICATION_CLASS = 'CRM_Herald_CivirulesAdapter_Action_Notification';

  private const DIGEST_CLASS = 'CRM_Herald_CivirulesAdapter_Action_Digest';

  private const FIELD_TRANSITION_CLASS = 'CRM_Herald_CivirulesAdapter_Condition_FieldTransition';

  /**
   * Retired actions → [successor, params transform]. Campaign title and goal
   * now come from the Campaign record.
   */
  private const RETIRED_ACTIONS = [
    'CRM_Herald_CivirulesActions_SendContributionAlert' => [self::NOTIFICATION_CLASS, 'withoutCampaignParams'],
    'CRM_Herald_CivirulesAdapter_Action_ContributionAlert' => [self::NOTIFICATION_CLASS, 'withoutCampaignParams'],
    'CRM_Herald_CivirulesActions_SendContributionDigest' => [self::DIGEST_CLASS, 'withoutDigestCampaignParams'],
    'CRM_Herald_CivirulesAdapter_Action_ContributionDigest' => [self::DIGEST_CLASS, 'withoutDigestCampaignParams'],
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
   * campaign_id stays: in a digest it filters the queries.
   */
  private const RETIRED_DIGEST_PARAMS = ['goal', 'campaign_title'];

  /**
   * Retired special-case conditions → the field transition each one expresses.
   */
  private const RETIRED_CONDITIONS = [
    'CRM_Herald_CivirulesConditions_PreviousContributionStatus' => [
      self::FIELD_TRANSITION_CLASS,
      'previousContributionStatus',
    ],
    'CRM_Herald_CivirulesAdapter_Condition_PreviousContributionStatus' => [
      self::FIELD_TRANSITION_CLASS,
      'previousContributionStatus',
    ],
    'CRM_Herald_CivirulesConditions_EventBecameInactive' => [self::FIELD_TRANSITION_CLASS, 'eventBecameInactive'],
    'CRM_Herald_CivirulesAdapter_Condition_EventBecameInactive' => [
      self::FIELD_TRANSITION_CLASS,
      'eventBecameInactive',
    ],
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
    $migrated = self::moveRuleRows('action', self::RETIRED_ACTIONS);
    $migrated += self::moveRuleRows('condition', self::RETIRED_CONDITIONS);
    \Civi\Herald\Condition\FieldTransitionWatchList::reset();
    return $migrated;
  }

  /**
   * @param 'action'|'condition' $kind
   * @param array<string, array{string, string}> $retired class name => [successor class, params transform]
   */
  private static function moveRuleRows(string $kind, array $retired): int {
    $entities = self::ENTITIES[$kind];
    $moved = 0;
    foreach ($retired as $class => [$targetClass, $transform]) {
      $handlerId = self::handlerId($entities['handler'], $class);
      if ($handlerId <= 0) {
        continue;
      }
      $targetId = self::handlerId($entities['handler'], $targetClass);
      if ($targetId <= 0) {
        throw new \CRM_Core_Exception("herald: {$targetClass} is not registered as a CiviRules {$kind}");
      }
      $paramsByRow = civicrm_api4($entities['rule'], 'get', [
        'select' => ['id', "{$kind}_params"],
        'where' => [["{$kind}_id", '=', $handlerId]],
        'checkPermissions' => FALSE,
      ])->indexBy('id')->column("{$kind}_params");
      foreach ($paramsByRow as $rowId => $params) {
        $rowId = (int) $rowId;
        civicrm_api4($entities['rule'], 'update', [
          'values' => [
            "{$kind}_id" => $targetId,
            "{$kind}_params" => self::transform($transform, is_array($params) ? $params : [], $rowId),
          ],
          'where' => [['id', '=', $rowId]],
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
    $ids = civicrm_api4($entity, 'get', [
      'select' => ['id'],
      'where' => [['class_name', '=', $class]],
      'checkPermissions' => FALSE,
    ])->column('id');
    return \Civi\Herald\Vendor\Civico\Stdlib\Cast::toInt($ids[0] ?? 0);
  }

  /**
   * @param array<array-key, mixed> $params
   * @return array<array-key, mixed>
   */
  private static function transform(string $transform, array $params, int $ruleRowId): array {
    return match ($transform) {
      'withoutCampaignParams' => self::withoutParams($params, self::RETIRED_ACTION_PARAMS, $ruleRowId),
      'withoutDigestCampaignParams' => self::withoutParams($params, self::RETIRED_DIGEST_PARAMS, $ruleRowId),
      'previousContributionStatus' => self::previousContributionStatus($params),
      'eventBecameInactive' => self::EVENT_BECAME_INACTIVE,
      default => throw new \CRM_Core_Exception("herald: unknown rule migration {$transform}"),
    };
  }

  /**
   * Campaign title and goal now come from the Campaign record; the log keeps
   * what an admin had configured so a goal missing there can be restored.
   *
   * @param array<array-key, mixed> $params
   * @param list<string> $retiredKeys
   * @return array<array-key, mixed>
   */
  private static function withoutParams(array $params, array $retiredKeys, int $ruleActionId): array {
    $dropped = array_intersect_key($params, array_flip($retiredKeys));
    if ($dropped !== []) {
      \Civi::log()->warning(
        'herald: retired action migrated, campaign params dropped',
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
