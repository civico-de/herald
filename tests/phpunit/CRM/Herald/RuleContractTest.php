<?php

declare(strict_types = 1);

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Event;
use Civi\Api4\Participant;
use Civi\Herald\PreEditSnapshot;
use Civi\Herald\Preflight\HandlerContract;
use Civi\Herald\Preflight\RuleHandlers;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Resolves each handler herald registers the way CiviRules does and runs its
 * parameter form, summary and evaluation against full and partial payloads.
 *
 * @group headless
 */
class CRM_Herald_RuleContractTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * Registered handler class → the test that exercises its contract.
   */
  private const SCENARIOS = [
    CRM_Herald_CivirulesAdapter_Condition_FieldTransition::class => 'testFieldTransitionContract',
    CRM_Herald_CivirulesAdapter_Action_Notification::class => 'testNotificationContract',
    CRM_Herald_CivirulesAdapter_Action_Digest::class => 'testDigestContract',
    CRM_Herald_CivirulesAdapter_Trigger_Schedule::class => 'testScheduleContract',
  ];

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    PreEditSnapshot::reset();
  }

  public function testEveryRegisteredHandlerIsUsable(): void {
    $problems = array_filter(array_map(
      static fn (array $handler): ?string => HandlerContract::inspect($handler['kind'], $handler['class']),
      RuleHandlers::registered(),
    ));
    self::assertSame([], $problems, 'registered CiviRules handlers CiviRules could not use');
  }

  public function testEveryRegisteredHandlerHasAContractScenario(): void {
    $registered = array_column(RuleHandlers::registered(), 'class');
    sort($registered);
    $covered = array_keys(self::SCENARIOS);
    sort($covered);
    self::assertSame($registered, $covered, 'add a contract scenario for each registered handler');
    foreach (self::SCENARIOS as $class => $test) {
      self::assertTrue(method_exists($this, $test), "{$class}: scenario {$test} does not exist");
    }
  }

  public function testFieldTransitionContract(): void {
    $params = [
      'entity' => 'Event',
      'field' => 'is_active',
      'from_operator' => '=',
      'from_value' => '1',
      'to_operator' => '=',
      'to_value' => '0',
    ];
    $condition = $this->condition(CRM_Herald_CivirulesAdapter_Condition_FieldTransition::class, $params);
    self::assertStringContainsString('id=7', (string) $condition->getExtraDataInputUrl(7));
    self::assertSame('Event.is_active: = 1 → = 0', $condition->userFriendlyConditionParams());
    $this->assertDeactivationScenarios($condition);

    // One-sided "is_active = 0" must not match a partial edit of an inactive event.
    $becameInactive = $this->condition(
      CRM_Herald_CivirulesAdapter_Condition_FieldTransition::class,
      ['entity' => 'Event', 'field' => 'is_active', 'to_operator' => '=', 'to_value' => '0'],
    );
    $inactive = $this->createEvent(active: FALSE);
    $before = $this->event($inactive);
    Event::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $inactive)
      ->addValue('title', 'Renamed while inactive')
      ->execute();
    self::assertFalse($becameInactive->isConditionValid($this->editTrigger(
      'Event',
      $inactive,
      ['id' => $inactive, 'title' => 'Renamed while inactive'],
      ['id' => $inactive, 'title' => $before['title']],
    )));
  }

  public function testFieldTransitionOnContributionStatus(): void {
    $pending = $this->statusId('Pending');
    $condition = $this->condition(
      CRM_Herald_CivirulesAdapter_Condition_FieldTransition::class,
      [
        'entity' => 'Contribution',
        'field' => 'contribution_status_id',
        'from_operator' => '=',
        'from_value' => (string) $pending,
      ],
    );

    // Full payload: Pending → Completed.
    $id = $this->createContribution('Pending');
    $before = $this->contribution($id);
    Contribution::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $id)
      ->addValue('contribution_status_id:name', 'Completed')
      ->execute();
    self::assertTrue($condition->isConditionValid($this->editTrigger(
      'Contribution',
      $id,
      $this->contribution($id),
      $before,
    )));

    // Partial payload: an unrelated edit of a completed contribution carries no status.
    $completed = $this->createContribution('Completed');
    PreEditSnapshot::reset();
    Contribution::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $completed)
      ->addValue('source', 'Partial edit')
      ->execute();
    self::assertFalse($condition->isConditionValid($this->editTrigger(
      'Contribution',
      $completed,
      ['id' => $completed, 'source' => 'Partial edit'],
      ['id' => $completed],
    )));
  }

  public function testNotificationContract(): void {
    $action = $this->action(CRM_Herald_CivirulesAdapter_Action_Notification::class, [
      'channel' => 'telegram',
      'template' => 'herald_event_registration_alert',
      'aggregate_minutes' => 15,
    ]);
    self::assertStringContainsString('id=7', (string) $action->getExtraDataInputUrl(7));
    self::assertIsString($action->userFriendlyConditionParams());

    $eventId = $this->createEvent(active: TRUE);
    $contactId = $this->createContact();
    $participantId = (int) Participant::create(checkPermissions: FALSE)
      ->addValue('event_id', $eventId)
      ->addValue('contact_id', $contactId)
      ->addValue('status_id:name', 'Registered')
      ->addValue('role_id:name', ['Attendee'])
      ->execute()
      ->single()['id'];
    $participant = (array) Participant::get(checkPermissions: FALSE)
      ->addWhere('id', '=', $participantId)
      ->execute()
      ->single();
    $full = new CRM_Civirules_TriggerData_Post('Participant', $participantId, $participant);
    $full->setEntityData('Event', $this->event($eventId));
    $action->processAction($full);
    self::assertSame(1, $this->bufferedRows());

    // Partial payload: only the participant id is known.
    $action->processAction(new CRM_Civirules_TriggerData_Post('Participant', $participantId, ['id' => $participantId]));
    self::assertSame(2, $this->bufferedRows());
  }

  public function testNotificationWithContributionPayload(): void {
    $action = $this->action(CRM_Herald_CivirulesAdapter_Action_Notification::class, [
      'channel' => 'telegram',
      'template' => 'herald_contribution_alert_completed',
      'aggregate_minutes' => 15,
    ]);

    $id = $this->createContribution('Pending');
    $before = $this->contribution($id);
    Contribution::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $id)
      ->addValue('contribution_status_id:name', 'Completed')
      ->execute();
    $action->processAction($this->editTrigger('Contribution', $id, $this->contribution($id), $before));
    self::assertSame(1, $this->bufferedRows());

    // Partial payload: the id alone; amount and status come from the stored row.
    $action->processAction($this->editTrigger('Contribution', $id, ['id' => $id], []));
    self::assertSame(2, $this->bufferedRows());
    self::assertSame(['25.00', '25.00'], $this->bufferedAmounts());
  }

  public function testDigestContract(): void {
    $digestId = \Civi\Api4\HeraldDigest::create(checkPermissions: FALSE)
      ->setValues(['name' => 'herald_test_contract', 'title' => 'Contract digest'])
      ->execute()
      ->single()['id'];
    \Civi\Api4\HeraldDigestBlock::create(checkPermissions: FALSE)
      ->setValues([
        'digest_id' => $digestId,
        'template' => 'herald_contribution_digest_daily',
        'show_histogram' => FALSE,
      ])
      ->execute();
    $action = $this->action(CRM_Herald_CivirulesAdapter_Action_Digest::class, [
      'digest' => 'herald_test_contract',
      'channel' => 'telegram',
      'aggregate_minutes' => 15,
    ]);
    self::assertStringContainsString('id=7', (string) $action->getExtraDataInputUrl(7));
    self::assertSame('Sends the digest &quot;Contract digest&quot;.', $action->userFriendlyConditionParams());

    // Cron triggers carry no entity at all; the digest must not need one.
    $action->processAction(new CRM_Civirules_TriggerData_Cron(NULL, 'Contribution', []));
    self::assertSame(1, $this->bufferedRows());
  }

  public function testScheduleContract(): void {
    $trigger = new CRM_Herald_CivirulesAdapter_Trigger_Schedule();
    self::assertStringContainsString('rule_id=7', (string) $trigger->getExtraDataInputUrl(7));
    self::assertNotSame('', $trigger->getHelpText('triggerDescription'));
    self::assertNotSame('', $trigger->getHelpText('triggerParamsHelp'));
    $trigger->setTriggerParams(serialize(['weekdays' => [1], 'hour' => 8]));
    self::assertSame('Every Monday at 08:00', $trigger->getHelpText('triggerDescriptionWithParams'));
    $entity = $trigger->getReactOnEntity();
    self::assertSame('civirule_rule', $entity->daoClass::getTableName());

    // Full payload: a configured schedule whose slot is due fires once per cron run.
    $rule = $this->scheduleRule(serialize(['hour' => 0]));
    self::assertSame(1, $this->processSchedule($rule, serialize(['hour' => 0]))['count']);
    self::assertSame(0, $this->processSchedule($rule, serialize(['hour' => 0]))['count']);

    // Partial payload: no hour. Nothing fires, and the description says why.
    $partial = $this->scheduleRule('');
    self::assertSame(0, $this->processSchedule($partial, '')['count']);
    $trigger->setTriggerParams('');
    self::assertStringStartsWith('Not configured', $trigger->getTriggerDescription());
  }

  private function scheduleRule(string $params): int {
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule (name, label, trigger_id, trigger_params, is_active, created_date)
       VALUES (%1, %1, (SELECT id FROM civirule_trigger WHERE name = 'herald_schedule'), %2, 1, '2020-01-01')",
      [1 => ['herald_contract_' . uniqid(), 'String'], 2 => [$params, 'String']],
    );
    return (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  /**
   * @return array<string, int>
   */
  private function processSchedule(int $rule, string $params): array {
    $trigger = new CRM_Herald_CivirulesAdapter_Trigger_Schedule();
    $trigger->setRuleId($rule);
    $trigger->setTriggerParams($params);
    return $trigger->process();
  }

  /**
   * The shared deactivation scenarios, each against the live row CiviRules
   * would have just written.
   */
  private function assertDeactivationScenarios(CRM_Civirules_Condition $condition): void {
    // Full payload: the event was switched off.
    $id = $this->createEvent(active: TRUE);
    $before = $this->event($id);
    Event::update(checkPermissions: FALSE)->addWhere('id', '=', $id)->addValue('is_active', value: FALSE)->execute();
    self::assertTrue($condition->isConditionValid($this->editTrigger('Event', $id, $this->event($id), $before)));

    // Partial payload: a title edit carries no is_active on either side.
    $active = $this->createEvent(active: TRUE);
    PreEditSnapshot::reset();
    Event::update(checkPermissions: FALSE)->addWhere('id', '=', $active)->addValue('title', 'Renamed')->execute();
    self::assertFalse($condition->isConditionValid($this->editTrigger(
      'Event',
      $active,
      ['id' => $active, 'title' => 'Renamed'],
      ['id' => $active, 'title' => 'Herald contract event'],
    )));
  }

  /**
   * @param array<string, mixed> $params
   */
  private function condition(string $class, array $params): CRM_Civirules_Condition {
    $condition = new $class();
    self::assertInstanceOf(CRM_Civirules_Condition::class, $condition);
    $condition->setRuleConditionData([
      'id' => 0,
      'rule_id' => 0,
      'condition_id' => 0,
      'condition_params' => serialize($params),
    ]);
    return $condition;
  }

  /**
   * @param array<string, mixed> $params
   */
  private function action(string $class, array $params): CRM_Civirules_Action {
    $action = new $class();
    self::assertInstanceOf(CRM_Civirules_Action::class, $action);
    $action->setRuleActionData(['id' => 0, 'rule_id' => 0, 'action_id' => 0, 'action_params' => serialize($params)]);
    return $action;
  }

  /**
   * @param array<string, mixed> $data
   * @param array<string, mixed> $original
   */
  private function editTrigger(string $entity, int $id, array $data, array $original): CRM_Civirules_TriggerData_Edit {
    return new CRM_Civirules_TriggerData_Edit($entity, $id, $data, $original);
  }

  private function createEvent(bool $active): int {
    return (int) Event::create(checkPermissions: FALSE)
      ->addValue('title', 'Herald contract event')
      ->addValue('start_date', '2026-09-01')
      ->addValue('event_type_id', 1)
      ->addValue('is_active', $active)
      ->execute()
      ->single()['id'];
  }

  /**
   * @return array<string, mixed>
   */
  private function event(int $id): array {
    return (array) Event::get(checkPermissions: FALSE)->addWhere('id', '=', $id)->execute()->single();
  }

  private function createContact(): int {
    return (int) Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Contract')
      ->addValue('last_name', 'Test')
      ->execute()
      ->single()['id'];
  }

  private function createContribution(string $status): int {
    return (int) Contribution::create(checkPermissions: FALSE)
      ->addValue('contact_id', $this->createContact())
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('contribution_status_id:name', $status)
      ->addValue('total_amount', 25)
      ->addValue('receive_date', '2026-09-01 10:00:00')
      ->execute()
      ->single()['id'];
  }

  /**
   * @return array<string, mixed>
   */
  private function contribution(int $id): array {
    return (array) Contribution::get(checkPermissions: FALSE)->addWhere('id', '=', $id)->execute()->single();
  }

  private function statusId(string $name): int {
    return (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $name);
  }

  /**
   * @return list<string>
   */
  private function bufferedAmounts(): array {
    $amounts = [];
    $dao = CRM_Core_DAO::executeQuery('SELECT amount FROM civicrm_herald_buffer ORDER BY id');
    while ($dao->fetch()) {
      $amounts[] = (string) $dao->amount;
    }
    return $amounts;
  }

  private function bufferedRows(): int {
    return (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer');
  }

}
