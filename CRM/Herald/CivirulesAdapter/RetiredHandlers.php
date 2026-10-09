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
      'civirule_action',
      'civirule_rule_action',
      'action',
      array_fill_keys(self::RETIRED_ACTIONS, 'withoutCampaignParams'),
      self::NOTIFICATION_CLASS,
    );
    $migrated += self::moveRuleRows(
      'civirule_condition',
      'civirule_rule_condition',
      'condition',
      self::RETIRED_CONDITIONS,
      self::FIELD_TRANSITION_CLASS,
    );
    \Civi\Herald\Condition\FieldTransitionWatchList::reset();
    return $migrated;
  }

  /**
   * @param 'civirule_action'|'civirule_condition' $handlerTable
   * @param 'civirule_rule_action'|'civirule_rule_condition' $ruleTable
   * @param 'action'|'condition' $kind
   * @param array<string, string> $retired class name => params transform method
   */
  private static function moveRuleRows(
    string $handlerTable,
    string $ruleTable,
    string $kind,
    array $retired,
    string $targetClass,
  ): int {
    $targetId = (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM `{$handlerTable}` WHERE class_name = %1",
      [1 => [$targetClass, 'String']],
    );
    if ($targetId <= 0) {
      throw new \CRM_Core_Exception("herald: {$targetClass} is not registered in {$handlerTable}");
    }
    $moved = 0;
    foreach ($retired as $class => $transform) {
      $handlerId = (int) \CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM `{$handlerTable}` WHERE class_name = %1",
        [1 => [$class, 'String']],
      );
      if ($handlerId <= 0) {
        continue;
      }
      /** @var \CRM_Core_DAO&object{id: string, params: ?string} $rows */
      $rows = \CRM_Core_DAO::executeQuery(
        "SELECT id, {$kind}_params AS params FROM `{$ruleTable}` WHERE {$kind}_id = %1",
        [1 => [$handlerId, 'Integer']],
      );
      while ($rows->fetch()) {
        \CRM_Core_DAO::executeQuery(
          "UPDATE `{$ruleTable}` SET {$kind}_id = %1, {$kind}_params = %2 WHERE id = %3",
          [
            1 => [$targetId, 'Integer'],
            2 => [serialize(self::transform($transform, self::decodeParams($rows->params))), 'String'],
            3 => [(int) $rows->id, 'Integer'],
          ],
        );
        $moved++;
      }
      \CRM_Core_DAO::executeQuery(
        "DELETE FROM `{$handlerTable}` WHERE id = %1",
        [1 => [$handlerId, 'Integer']],
      );
    }
    return $moved;
  }

  /**
   * @param array<array-key, mixed> $params
   * @return array<array-key, mixed>
   */
  private static function transform(string $transform, array $params): array {
    return match ($transform) {
      'withoutCampaignParams' => array_diff_key($params, array_flip(self::RETIRED_ACTION_PARAMS)),
      'previousContributionStatus' => self::previousContributionStatus($params),
      'eventBecameInactive' => self::EVENT_BECAME_INACTIVE,
      default => throw new \CRM_Core_Exception("herald: unknown rule migration {$transform}"),
    };
  }

  /**
   * "Previous status was X"; without a configured status the old condition never matched.
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
      'to_operator' => '',
      'to_value' => '',
    ];
  }

  /**
   * @return array<array-key, mixed>
   */
  private static function decodeParams(?string $raw): array {
    $decoded = $raw === NULL || $raw === '' ? [] : unserialize($raw, ['allowed_classes' => FALSE]);
    return is_array($decoded) ? $decoded : [];
  }

}
