<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

// phpcs:disable Drupal.Classes.ClassDeclaration.InterfaceWrongIndent
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

  // phpcs:enable Drupal.Classes.ClassDeclaration.InterfaceWrongIndent

  private const OLD_CLASS = 'CRM_Herald_CivirulesActions_SendContributionAlert';

  private const NEW_CLASS = 'CRM_Herald_CivirulesAdapter_Action_SendContributionAlert';

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      ->apply();
  }

  public function testPreRenameRuleSurvivesTheUpgrade(): void {
    $actionId = $this->actionIdByName('herald_contribution_alert');
    // Rewind the DB to its pre-upgrade state.
    \CRM_Core_DAO::executeQuery(
      'UPDATE civirule_action SET class_name = %1 WHERE id = %2',
      [1 => [self::OLD_CLASS, 'String'], 2 => [$actionId, 'Integer']],
    );
    $ruleActionId = $this->createRuleAction($actionId, [
      'channel' => 'telegram',
      'template' => 'herald_contribution_alert_completed',
      'aggregate_minutes' => 30,
      'include_status_transition' => FALSE,
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
    $actionId = $this->actionIdByName('herald_contribution_alert');
    \CRM_Core_DAO::executeQuery(
      'UPDATE civirule_action SET class_name = %1 WHERE id = %2',
      [1 => [self::OLD_CLASS, 'String'], 2 => [$actionId, 'Integer']],
    );

    // The manifests match on `name`, so re-registering is enough on its own.
    CRM_Herald_CivirulesAdapter_Registrar::register(dirname(__DIR__, 5));

    self::assertSame(self::NEW_CLASS, $this->classNameOf($actionId));
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
