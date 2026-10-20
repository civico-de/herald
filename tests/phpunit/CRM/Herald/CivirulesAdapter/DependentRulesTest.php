<?php

declare(strict_types = 1);

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class CRM_Herald_CivirulesAdapter_DependentRulesTest extends TestCase implements
  HeadlessInterface,
  TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testSwitchesOffRulesThatUseAHeraldHandlerOnly(): void {
    $trigger = $this->trigger();
    $withCondition = $this->rule($trigger);
    $this->addCondition($withCondition, 'herald_field_transition');
    $this->addAction($withCondition, $this->foreignAction());
    $withAction = $this->rule($trigger);
    $this->addAction($withAction, $this->actionId('herald_send'));
    $foreign = $this->rule($trigger);
    $this->addAction($foreign, $this->foreignAction());

    $switchedOff = CRM_Herald_CivirulesAdapter_DependentRules::disable();

    self::assertSame([$withCondition, $withAction], $switchedOff);
    self::assertSame(
      [$withCondition => FALSE, $withAction => FALSE, $foreign => TRUE],
      $this->activeById([$withCondition, $withAction, $foreign]),
    );
  }

  public function testSwitchesOffARuleWhoseTriggerIsOffNow(): void {
    $trigger = $this->trigger();
    $rule = $this->rule($trigger);
    $this->addCondition($rule, 'herald_field_transition');
    $this->addAction($rule, $this->foreignAction());
    CiviRulesTrigger::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $trigger)
      ->addValue('is_active', FALSE)
      ->execute();

    self::assertSame([$rule], CRM_Herald_CivirulesAdapter_DependentRules::disable());
    self::assertSame([$rule => FALSE], $this->activeById([$rule]));
  }

  public function testDeletesTheRulesOnAHeraldTriggerWithTheirActions(): void {
    $scheduled = $this->rule($this->scheduleTrigger());
    $this->addAction($scheduled, $this->foreignAction());
    $foreign = $this->rule($this->trigger());
    $this->addAction($foreign, $this->actionId('herald_send'));
    $name = CiviRulesRule::get(checkPermissions: FALSE)->addWhere('id', '=', $scheduled)->execute()->single()['name'];

    self::assertSame([$scheduled => $name], CRM_Herald_CivirulesAdapter_DependentRules::deleteTriggered());
    self::assertSame([$foreign => TRUE], $this->activeById([$scheduled, $foreign]));
    self::assertSame(
      0,
      CiviRulesRuleAction::get(checkPermissions: FALSE)->addWhere('rule_id', '=', $scheduled)->execute()->count(),
    );
  }

  public function testTheScheduleTriggerCanBeDeletedOnceItsRulesAreGone(): void {
    $trigger = $this->scheduleTrigger();
    $this->rule($trigger);

    CRM_Herald_CivirulesAdapter_DependentRules::deleteTriggered();
    CiviRulesTrigger::delete(checkPermissions: FALSE)->addWhere('id', '=', $trigger)->execute();

    self::assertSame(
      0,
      CiviRulesTrigger::get(checkPermissions: FALSE)->addWhere('id', '=', $trigger)->execute()->count(),
    );
  }

  /**
   * Core disables a module's managed entities on disable; the re-enable reconcile
   * must switch herald's handlers back on.
   */
  public function testReconcileReactivatesHandlersCoreDisabled(): void {
    CiviRulesTrigger::update(checkPermissions: FALSE)
      ->addWhere('name', '=', 'herald_schedule')
      ->addValue('is_active', FALSE)
      ->execute();
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_managed SET checksum = 'disabled' WHERE module = 'herald' AND name = 'herald_civirules_herald_schedule'",
    );

    CRM_Herald_Upgrader_Reconcile::run();

    self::assertTrue(
      CiviRulesTrigger::get(checkPermissions: FALSE)
        ->addWhere('name', '=', 'herald_schedule')
        ->execute()
        ->single()['is_active'],
    );
  }

  private function trigger(): int {
    return (int) CiviRulesTrigger::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_' . uniqid(),
        'label' => 'herald test trigger',
        'class_name' => 'CRM_Civirules_Trigger_Post',
        'object_name' => 'Event',
        'op' => 'edit',
        'cron' => FALSE,
        'is_active' => TRUE,
      ])
      ->execute()
      ->single()['id'];
  }

  private function scheduleTrigger(): int {
    return (int) CiviRulesTrigger::get(checkPermissions: FALSE)
      ->addWhere('name', '=', 'herald_schedule')
      ->execute()
      ->single()['id'];
  }

  private function rule(int $triggerId): int {
    return (int) CiviRulesRule::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_' . uniqid(),
        'label' => 'herald test rule',
        'trigger_id' => $triggerId,
        'is_active' => TRUE,
      ])
      ->execute()
      ->single()['id'];
  }

  private function addCondition(int $ruleId, string $name): void {
    $conditionId = CiviRulesCondition::get(checkPermissions: FALSE)
      ->addWhere('name', '=', $name)
      ->execute()
      ->single()['id'];
    CiviRulesRuleCondition::create(checkPermissions: FALSE)
      ->setValues(['rule_id' => $ruleId, 'condition_id' => $conditionId, 'is_active' => TRUE])
      ->execute();
  }

  private function addAction(int $ruleId, int $actionId): void {
    CiviRulesRuleAction::create(checkPermissions: FALSE)
      ->setValues(['rule_id' => $ruleId, 'action_id' => $actionId, 'is_active' => TRUE])
      ->execute();
  }

  private function actionId(string $name): int {
    return (int) CiviRulesAction::get(checkPermissions: FALSE)
      ->addWhere('name', '=', $name)
      ->execute()
      ->single()['id'];
  }

  private function foreignAction(): int {
    return (int) CiviRulesAction::get(checkPermissions: FALSE)
      ->addWhere('class_name', 'NOT LIKE', 'CRM_Herald%')
      ->setLimit(1)
      ->execute()
      ->single()['id'];
  }

  /**
   * @param list<int> $ids
   * @return array<int, bool>
   */
  private function activeById(array $ids): array {
    $active = [];
    foreach (CiviRulesRule::get(checkPermissions: FALSE)->addWhere('id', 'IN', $ids)->execute() as $rule) {
      $active[(int) $rule['id']] = (bool) $rule['is_active'];
    }
    ksort($active);
    return $active;
  }

}
