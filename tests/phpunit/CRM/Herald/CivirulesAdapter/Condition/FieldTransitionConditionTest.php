<?php

declare(strict_types = 1);

use Civi\Herald\PreEditSnapshot;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

// phpcs:disable Drupal.Classes.ClassDeclaration.InterfaceWrongIndent
// ckfmt wraps this declaration with 2-space continuations; the sniff wants 4.
/**
 * End-to-end adapter test: CiviRules trigger data → Herald.checkFieldTransition.
 *
 * Exercises the generic transition condition against a real Contribution row:
 * both sides configured, snapshot vs. original-data precedence, unknown
 * previous value, and one-sided (after-only) configuration.
 *
 * @group headless
 */
class CRM_Herald_CivirulesAdapter_Condition_FieldTransitionConditionTest extends TestCase implements
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

  protected function setUp(): void {
    parent::setUp();
    PreEditSnapshot::reset();
  }

  private function createContribution(int $statusId): int {
    $contactId = (int) \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Herald')
      ->addValue('last_name', 'Fixture')
      ->execute()
      ->first()['id'];
    return (int) \Civi\Api4\Contribution::create(checkPermissions: FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', 1)
      ->addValue('total_amount', 10)
      ->addValue('contribution_status_id', $statusId)
      ->execute()
      ->first()['id'];
  }

  /**
   * @param array<string, string> $params
   */
  private function condition(array $params): CRM_Herald_CivirulesAdapter_Condition_FieldTransition {
    $condition = new CRM_Herald_CivirulesAdapter_Condition_FieldTransition();
    $condition->setRuleConditionData(['condition_params' => serialize($params)]);
    return $condition;
  }

  private function triggerData(int $contributionId): CRM_Civirules_TriggerData_TriggerData {
    $data = new class() extends CRM_Civirules_TriggerData_TriggerData {};
    $data->setEntityData('Contribution', ['id' => $contributionId]);
    return $data;
  }

  private function triggerDataWithOriginal(
    int $contributionId,
    array $original,
  ): CRM_Civirules_TriggerData_TriggerData {
    $data = new class() extends CRM_Civirules_TriggerData_TriggerData implements
      CRM_Civirules_TriggerData_Interface_OriginalData {

      /**
       * @var array<string, mixed> */
      public array $original = [];

      public function getOriginalData() {
        return $this->original;
      }

      public function getOriginalEntity() {
        return 'Contribution';
      }

    };
    $data->original = $original;
    $data->setEntityData('Contribution', ['id' => $contributionId]);
    return $data;
  }

  /**
   * @return array<string, string>
   */
  private function pendingToCompletedParams(): array {
    // 2 = Pending, 1 = Completed in the standard contribution_status group.
    return [
      'entity' => 'Contribution',
      'field' => 'contribution_status_id',
      'from_operator' => '=',
      'from_value' => '2',
      'to_operator' => '=',
      'to_value' => '1',
    ];
  }

  public function testFiresOnSnapshotTransition(): void {
    $id = $this->createContribution(1);
    PreEditSnapshot::remember('Contribution', $id, 'contribution_status_id', 2);
    $this->assertTrue(
      $this->condition($this->pendingToCompletedParams())->isConditionValid($this->triggerData($id)),
    );
  }

  public function testDoesNotFireWhenPreviousValueUnknown(): void {
    $id = $this->createContribution(1);
    $this->assertFalse(
      $this->condition($this->pendingToCompletedParams())->isConditionValid($this->triggerData($id)),
    );
  }

  public function testDoesNotFireWhenCurrentValueDiffers(): void {
    $id = $this->createContribution(2);
    PreEditSnapshot::remember('Contribution', $id, 'contribution_status_id', 2);
    $this->assertFalse(
      $this->condition($this->pendingToCompletedParams())->isConditionValid($this->triggerData($id)),
    );
  }

  public function testOriginalDataBeatsSnapshot(): void {
    // Original data is per-edit and wins; the request-wide snapshot is the fallback.
    $id = $this->createContribution(1);
    PreEditSnapshot::remember('Contribution', $id, 'contribution_status_id', 1);
    $this->assertTrue(
      $this->condition($this->pendingToCompletedParams())
        ->isConditionValid($this->triggerDataWithOriginal($id, ['contribution_status_id' => 2])),
    );
  }

  public function testAfterOnlySideRequiresAnActualChange(): void {
    // One-sided conditions must see a changed value, or every unrelated edit
    // of a row already in the target state would re-fire them.
    $id = $this->createContribution(1);
    $params = $this->pendingToCompletedParams();
    $params['from_operator'] = '';
    $params['from_value'] = '';
    // Unknown previous value: the change cannot be verified — no match.
    $this->assertFalse(
      $this->condition($params)->isConditionValid($this->triggerData($id)),
    );
    // Known and different previous value: match.
    PreEditSnapshot::remember('Contribution', $id, 'contribution_status_id', 2);
    $this->assertTrue(
      $this->condition($params)->isConditionValid($this->triggerData($id)),
    );
  }

  public function testAfterOnlySideDoesNotFireWithoutAChange(): void {
    $id = $this->createContribution(1);
    $params = $this->pendingToCompletedParams();
    $params['from_operator'] = '';
    $params['from_value'] = '';
    PreEditSnapshot::remember('Contribution', $id, 'contribution_status_id', 1);
    $this->assertFalse(
      $this->condition($params)->isConditionValid($this->triggerData($id)),
    );
  }

  /**
   * Contact edits fire hook_civicrm_pre with objectName Individual/…, while
   * conditions are configured against the APIv4 entity "Contact" — the watch
   * list must fold the subtype names so the snapshot is still captured.
   */
  public function testSnapshotCapturedOnIndividualEditForContactCondition(): void {
    $conditionId = (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT id FROM civirule_condition WHERE class_name = %1',
      [1 => [\Civi\Herald\Condition\FieldTransitionWatchList::ADAPTER_CLASS, 'String']],
    );
    $this->assertGreaterThan(0, $conditionId, 'herald_field_transition not registered');
    $triggerId = (int) \CRM_Core_DAO::singleValueQuery('SELECT MIN(id) FROM civirule_trigger');
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule (name, label, trigger_id, is_active, created_date)
       VALUES ('herald_test_contact_rule', 'Herald test contact rule', %1, 1, NOW())",
      [1 => [$triggerId, 'Integer']],
    );
    $ruleId = (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_condition (rule_id, condition_id, condition_params)
       VALUES (%1, %2, %3)',
      [
        1 => [$ruleId, 'Integer'],
        2 => [$conditionId, 'Integer'],
        3 => [
          serialize([
            'entity' => 'Contact',
            'field' => 'do_not_email',
            'from_operator' => '=',
            'from_value' => '0',
            'to_operator' => '=',
            'to_value' => '1',
          ]),
          'String',
        ],
      ],
    );
    \Civi\Herald\Condition\FieldTransitionWatchList::reset();

    $contactId = (int) \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Herald')
      ->addValue('last_name', 'WatchFixture')
      ->addValue('do_not_email', value: FALSE)
      ->execute()
      ->first()['id'];
    // The edit fires hook_civicrm_pre with objectName "Individual".
    \Civi\Api4\Contact::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('do_not_email', value: TRUE)
      ->execute();

    $snapshot = PreEditSnapshot::get('Contact', $contactId, 'do_not_email');
    $this->assertNotNull($snapshot, 'no pre-edit snapshot captured for the Individual edit');
    $this->assertEquals(0, (int) (is_scalar($snapshot) ? $snapshot : -1));

    \Civi\Herald\Condition\FieldTransitionWatchList::reset();
  }

  public function testUnconfiguredConditionIsFalse(): void {
    $id = $this->createContribution(1);
    $this->assertFalse(
      $this->condition([])->isConditionValid($this->triggerData($id)),
    );
  }

}
