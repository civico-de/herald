<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

// ckfmt wraps this declaration with 2-space continuations; the sniff wants 4.
/**
 * A rule configured BEFORE the handlers moved into CivirulesAdapter must keep
 * working after the upgrade.
 *
 * CiviRules instantiates handlers by the class name stored in
 * civirule_action.class_name, so the rename is a data migration. This walks the
 * real path: put the pre-rename class name in the DB, run the upgrade step,
 * then let CiviRules resolve and execute the action exactly as the rule engine
 * would. Execution uses aggregate_minutes so the run ends in the alert buffer —
 * the full render-and-dispatch path with no outbound traffic.
 *
 * @group headless
 */
class CRM_Herald_CivirulesAdapter_UpgradePathTest extends TestCase implements
  HeadlessInterface,
  HookInterface,
  TransactionalInterface {

  private const OLD_CLASS = 'CRM_Herald_CivirulesActions_SendNotification';

  private const NEW_CLASS = 'CRM_Herald_CivirulesAdapter_Action_Notification';

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      ->apply();
  }

  public function testPreRenameRuleSurvivesTheUpgrade(): void {
    $actionId = $this->actionIdByName('herald_send');
    // Rewind the DB to its pre-upgrade state.
    \CRM_Core_DAO::executeQuery(
      'UPDATE civirule_action SET class_name = %1 WHERE id = %2',
      [1 => [self::OLD_CLASS, 'String'], 2 => [$actionId, 'Integer']],
    );
    $ruleActionId = $this->createRuleAction($actionId, [
      'channel' => 'telegram',
      'template' => 'herald_contribution_alert_completed',
      'aggregate_minutes' => 30,
    ]);

    $migrated = CRM_Herald_CivirulesAdapter_Registrar::migrateRenamedClasses();
    self::assertGreaterThanOrEqual(1, $migrated);
    self::assertSame(self::NEW_CLASS, $this->classNameOf($actionId));

    // Resolve exactly the way the rule engine does — this is the instanceof
    // contract the adapter exists to satisfy.
    $action = CRM_Civirules_BAO_CiviRulesAction::getActionObjectById($actionId);
    self::assertInstanceOf(CRM_Civirules_Action::class, $action);

    $action->setRuleActionData($this->ruleActionRow($ruleActionId));
    $action->processAction($this->triggerData());

    self::assertSame(
      1,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer'),
      'the migrated action did not reach the dispatcher',
    );
  }

  public function testEnableAlsoRewritesTheClassName(): void {
    $actionId = $this->actionIdByName('herald_send');
    \CRM_Core_DAO::executeQuery(
      'UPDATE civirule_action SET class_name = %1 WHERE id = %2',
      [1 => [self::OLD_CLASS, 'String'], 2 => [$actionId, 'Integer']],
    );

    // The manifests match on `name`, so re-registering is enough on its own.
    CRM_Herald_CivirulesAdapter_Registrar::register(dirname(__DIR__, 5));

    self::assertSame(self::NEW_CLASS, $this->classNameOf($actionId));
  }

  /**
   * 0.7.0 regression: manifests and managed entities added in a release must
   * reach EXISTING installs via the upgrader, not only via install/enable.
   * upgrade_1003 delegates to reconcile(); this exercises that path against a
   * DB missing the 0.7.0 additions.
   */
  public function testReconcileRegistersManifestAdditionsOnExistingInstalls(): void {
    \CRM_Core_DAO::executeQuery(
      "DELETE FROM civirule_condition WHERE name = 'herald_field_transition'",
    );
    \CRM_Core_DAO::executeQuery(
      "DELETE FROM civicrm_managed WHERE module = 'herald'
       AND name = 'OptionValue_herald_subscription_type_weekly_digest'",
    );
    \CRM_Core_DAO::executeQuery(
      "DELETE ov FROM civicrm_option_value ov
       JOIN civicrm_option_group og ON og.id = ov.option_group_id
       WHERE og.name = 'herald_subscription_type' AND ov.name = 'weekly_digest'",
    );

    CRM_Herald_Upgrader::reconcile(dirname(__DIR__, 5));

    self::assertSame(
      'CRM_Herald_CivirulesAdapter_Condition_FieldTransition',
      (string) \CRM_Core_DAO::singleValueQuery(
        "SELECT class_name FROM civirule_condition WHERE name = 'herald_field_transition'",
      ),
      'upgrade reconcile did not (re)register the field-transition condition',
    );
    self::assertSame(
      1,
      (int) \CRM_Core_DAO::singleValueQuery(
        "SELECT COUNT(*) FROM civicrm_option_value ov
         JOIN civicrm_option_group og ON og.id = ov.option_group_id
         WHERE og.name = 'herald_subscription_type' AND ov.name = 'weekly_digest'",
      ),
      'upgrade reconcile did not restore the managed option value',
    );
  }

  public function testRetiredContributionAlertMovesOntoTheNotification(): void {
    $alertId = $this->insertHandler(
      'civirule_action',
      'herald_contribution_alert',
      'CRM_Herald_CivirulesAdapter_Action_ContributionAlert',
    );
    $ruleActionId = $this->createRuleAction($alertId, [
      'channel' => 'telegram',
      'template' => 'herald_contribution_alert_completed',
      'campaign_id' => 2,
      'goal' => 30_000,
      'campaign_title' => 'Campaign 2026',
      'chat_id' => '-1',
      'aggregate_minutes' => 5,
    ]);

    self::assertSame(1, CRM_Herald_CivirulesAdapter_RetiredHandlers::migrate());

    $sendId = $this->actionIdByName('herald_send');
    self::assertSame($sendId, $this->handlerOf('civirule_rule_action', 'action', $ruleActionId));
    self::assertSame(
      [
        'channel' => 'telegram',
        'template' => 'herald_contribution_alert_completed',
        'chat_id' => '-1',
        'aggregate_minutes' => 5,
      ],
      $this->paramsOf('civirule_rule_action', 'action', $ruleActionId),
    );
    self::assertFalse($this->handlerExists('civirule_action', $alertId));
    self::assertSame(0, CRM_Herald_CivirulesAdapter_RetiredHandlers::migrate(), 'a second run must be a no-op');

    // The moved action runs the way the rule engine resolves it.
    $action = CRM_Civirules_BAO_CiviRulesAction::getActionObjectById($sendId);
    self::assertInstanceOf(CRM_Civirules_Action::class, $action);
    $action->setRuleActionData($this->ruleActionRow($ruleActionId));
    $action->processAction($this->triggerData());
    self::assertSame(1, (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer'));
  }

  public function testRetiredContributionDigestMovesOntoTheDigest(): void {
    $digestId = $this->insertHandler(
      'civirule_action',
      'herald_contribution_digest',
      'CRM_Herald_CivirulesAdapter_Action_ContributionDigest',
    );
    $ruleActionId = $this->createRuleAction($digestId, [
      'channel' => 'telegram',
      'template' => 'herald_contribution_digest_daily',
      'campaign_id' => 2,
      'goal' => 30_000,
      'campaign_title' => 'Campaign 2026',
    ]);

    self::assertSame(1, CRM_Herald_CivirulesAdapter_RetiredHandlers::migrate());

    self::assertSame(
      $this->actionIdByName('herald_digest'),
      $this->handlerOf('civirule_rule_action', 'action', $ruleActionId),
    );
    // campaign_id filters the digest's queries and stays.
    self::assertSame(
      ['channel' => 'telegram', 'template' => 'herald_contribution_digest_daily', 'campaign_id' => 2],
      $this->paramsOf('civirule_rule_action', 'action', $ruleActionId),
    );
    self::assertFalse($this->handlerExists('civirule_action', $digestId));
  }

  public function testNothingToMigrateNeedsNoTargetHandler(): void {
    \CRM_Core_DAO::executeQuery("DELETE FROM civirule_condition WHERE name = 'herald_field_transition'");
    self::assertSame(0, CRM_Herald_CivirulesAdapter_RetiredHandlers::migrate());
  }

  public function testRetiredConditionsMoveOntoTheFieldTransition(): void {
    $previousId = $this->insertHandler(
      'civirule_condition',
      'herald_previous_contribution_status',
      'CRM_Herald_CivirulesAdapter_Condition_PreviousContributionStatus',
    );
    $inactiveId = $this->insertHandler(
      'civirule_condition',
      'herald_event_became_inactive',
      'CRM_Herald_CivirulesConditions_EventBecameInactive',
    );
    $ruleId = $this->ruleIdOf($this->createRuleAction($this->actionIdByName('herald_send'), []));
    $rows = [
      $this->createRuleCondition($ruleId, $previousId, ['previous_status_id' => 2]),
      $this->createRuleCondition($ruleId, $previousId, []),
      $this->createRuleCondition($ruleId, $inactiveId, []),
    ];

    self::assertSame(3, CRM_Herald_CivirulesAdapter_RetiredHandlers::migrate());

    $fieldTransitionId = (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civirule_condition WHERE name = 'herald_field_transition'",
    );
    $status = ['entity' => 'Contribution', 'field' => 'contribution_status_id'];
    $expected = [
      // Two-sided: the old condition also matched edits that kept the status.
      $status + ['from_operator' => '=', 'from_value' => '2', 'to_operator' => 'not_empty', 'to_value' => ''],
      // Without a configured status the old condition never matched; neither does this.
      $status + ['from_operator' => '', 'from_value' => '', 'to_operator' => '', 'to_value' => ''],
      [
        'entity' => 'Event',
        'field' => 'is_active',
        'from_operator' => '=',
        'from_value' => '1',
        'to_operator' => '=',
        'to_value' => '0',
      ],
    ];
    foreach ($rows as $i => $row) {
      self::assertSame($fieldTransitionId, $this->handlerOf('civirule_rule_condition', 'condition', $row));
      self::assertSame($expected[$i], $this->paramsOf('civirule_rule_condition', 'condition', $row));
    }
    self::assertFalse($this->handlerExists('civirule_condition', $previousId));
    self::assertFalse($this->handlerExists('civirule_condition', $inactiveId));
  }

  private function handlerExists(string $table, int $id): bool {
    return (bool) \CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM `{$table}` WHERE id = %1",
      [1 => [$id, 'Integer']],
    );
  }

  private function insertHandler(string $table, string $name, string $class): int {
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `{$table}` (name, label, class_name, is_active) VALUES (%1, %1, %2, 1)",
      [1 => [$name, 'String'], 2 => [$class, 'String']],
    );
    return (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  /**
   * @param array<string, mixed> $params
   */
  private function createRuleCondition(int $ruleId, int $conditionId, array $params): int {
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_condition (rule_id, condition_id, condition_params, is_active)
       VALUES (%1, %2, %3, 1)',
      [1 => [$ruleId, 'Integer'], 2 => [$conditionId, 'Integer'], 3 => [serialize($params), 'String']],
    );
    return (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  private function ruleIdOf(int $ruleActionId): int {
    return (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT rule_id FROM civirule_rule_action WHERE id = %1',
      [1 => [$ruleActionId, 'Integer']],
    );
  }

  private function handlerOf(string $table, string $kind, int $id): int {
    return (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT {$kind}_id FROM `{$table}` WHERE id = %1",
      [1 => [$id, 'Integer']],
    );
  }

  /**
   * @return array<array-key, mixed>
   */
  private function paramsOf(string $table, string $kind, int $id): array {
    $raw = (string) \CRM_Core_DAO::singleValueQuery(
      "SELECT {$kind}_params FROM `{$table}` WHERE id = %1",
      [1 => [$id, 'Integer']],
    );
    return (array) unserialize($raw, ['allowed_classes' => FALSE]);
  }

  private function actionIdByName(string $name): int {
    return (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT id FROM civirule_action WHERE name = %1',
      [1 => [$name, 'String']],
    );
  }

  private function classNameOf(int $actionId): string {
    return (string) \CRM_Core_DAO::singleValueQuery(
      'SELECT class_name FROM civirule_action WHERE id = %1',
      [1 => [$actionId, 'Integer']],
    );
  }

  /**
   * @param array<string, mixed> $params
   */
  private function createRuleAction(int $actionId, array $params): int {
    $triggerId = (int) \CRM_Core_DAO::singleValueQuery('SELECT MIN(id) FROM civirule_trigger');
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule (name, label, trigger_id, is_active)
       VALUES ('herald_test', 'herald test', %1, 1)",
      [1 => [$triggerId, 'Integer']],
    );
    $ruleId = (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_action (rule_id, action_id, action_params, is_active)
       VALUES (%1, %2, %3, 1)',
      [1 => [$ruleId, 'Integer'], 2 => [$actionId, 'Integer'], 3 => [serialize($params), 'String']],
    );
    return (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  /**
   * @return array<string, mixed>
   */
  private function ruleActionRow(int $ruleActionId): array {
    $dao = \CRM_Core_DAO::executeQuery(
      'SELECT * FROM civirule_rule_action WHERE id = %1',
      [1 => [$ruleActionId, 'Integer']],
    );
    $dao->fetch();
    /** @var array<string, mixed> */
    return $dao->toArray();
  }

  private function triggerData(): CRM_Civirules_TriggerData_TriggerData {
    $data = new class() extends CRM_Civirules_TriggerData_TriggerData {};
    $data->setEntityData('Contribution', [
      'id' => 4711,
      'total_amount' => 25.0,
      'contribution_status_id' => 1,
      'source' => 'Test',
    ]);
    return $data;
  }

}
