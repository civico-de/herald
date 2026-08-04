<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

// phpcs:disable Drupal.Classes.ClassDeclaration.InterfaceWrongIndent
/**
 * The action adapters do one thing: unpack the trigger and hand over to a
 * Herald.* APIv4 action. Asserted by running each adapter with an aggregation
 * window, which ends the pipeline in the alert buffer instead of on the wire.
 *
 * @group headless
 */
class CRM_Herald_CivirulesAdapter_ActionDelegationTest extends TestCase implements
  HeadlessInterface,
  HookInterface,
  TransactionalInterface {

  // phpcs:enable Drupal.Classes.ClassDeclaration.InterfaceWrongIndent

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      ->apply();
  }

  public function testSendNotificationReachesTheDispatcher(): void {
    $action = new CRM_Herald_CivirulesAdapter_Action_SendNotification();
    $action->setRuleActionData($this->ruleActionData([
      'channel' => 'telegram',
      'template' => 'herald_event_created_alert',
      'aggregate_minutes' => 15,
      'vars' => ['event' => ['id' => 1, 'title' => 'Adapter test', 'start_date' => '2026-09-01', 'is_active' => 1]],
    ]));

    $action->processAction(new class() extends CRM_Civirules_TriggerData_TriggerData {});

    self::assertSame(1, $this->bufferedRows());
  }

  public function testSendContributionDigestReachesTheDispatcher(): void {
    $action = new CRM_Herald_CivirulesAdapter_Action_SendContributionDigest();
    $action->setRuleActionData($this->ruleActionData([
      'channel' => 'telegram',
      'template' => 'herald_contribution_digest_daily',
      'period' => 'yesterday',
      'aggregate_minutes' => 15,
      'show_histogram' => FALSE,
    ]));

    $action->processAction(new class() extends CRM_Civirules_TriggerData_TriggerData {});

    self::assertSame(1, $this->bufferedRows());
  }

  public function testContributionAlertRefusesATriggerWithoutAContribution(): void {
    $action = new CRM_Herald_CivirulesAdapter_Action_SendContributionAlert();
    $action->setRuleActionData($this->ruleActionData(['template' => 'herald_contribution_alert_completed']));

    $this->expectException(\CRM_Core_Exception::class);
    $action->processAction(new class() extends CRM_Civirules_TriggerData_TriggerData {});
  }

  /**
   * The row shape CiviRules hands a handler; only action_params is read.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  private function ruleActionData(array $params): array {
    return ['id' => 0, 'rule_id' => 0, 'action_id' => 0, 'action_params' => serialize($params)];
  }

  private function bufferedRows(): int {
    return (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer');
  }

}
