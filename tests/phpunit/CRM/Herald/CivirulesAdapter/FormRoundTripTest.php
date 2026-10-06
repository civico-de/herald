<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Every herald edit link names its row the way CiviRules' base form reads it,
 * and every parameter form saves what was submitted and shows it on reload.
 * A foreign rule's row sits first in each table, where a lost id would land.
 *
 * @group headless
 */
class CRM_Herald_CivirulesAdapter_FormRoundTripTest extends TestCase implements
  HeadlessInterface,
  TransactionalInterface {

  private const UNTOUCHED = 'a:1:{s:6:"marker";s:7:"foreign";}';

  /**
   * Stands in for the form's first template option until the form is built.
   */
  private const FIRST_TEMPLATE = '@first';

  /**
   * How CiviRules stores and loads rule actions.
   */
  private const ACTION = [
    'table' => 'civirule_rule_action',
    'handlerTable' => 'civirule_action',
    'handlerColumn' => 'action_id',
    'paramsColumn' => 'action_params',
  ];

  /**
   * How CiviRules stores and loads rule conditions.
   */
  private const CONDITION = [
    'table' => 'civirule_rule_condition',
    'handlerTable' => 'civirule_condition',
    'handlerColumn' => 'condition_id',
    'paramsColumn' => 'condition_params',
  ];

  /**
   * @var array<string, int> table => id of the foreign rule's row
   */
  private array $decoys = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    $foreignRule = $this->rule('herald_test_foreign');
    foreach ([self::ACTION, self::CONDITION] as $spec) {
      CRM_Core_DAO::executeQuery("DELETE FROM {$spec['table']}");
      $handlerId = (int) CRM_Core_DAO::singleValueQuery(
        "SELECT MIN(id) FROM {$spec['handlerTable']} WHERE class_name NOT LIKE 'CRM_Herald%'",
      );
      $this->decoys[$spec['table']] = $this->insert($spec, $handlerId, $foreignRule);
    }
  }

  protected function tearDown(): void {
    foreach ([self::ACTION, self::CONDITION] as $spec) {
      $this->assertSame(self::UNTOUCHED, $this->storedParams($spec, $this->decoys[$spec['table']]));
    }
    parent::tearDown();
  }

  private function rule(string $name): int {
    CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule (name, label, trigger_id, is_active, created_date)
       VALUES (%1, %1, (SELECT MIN(id) FROM civirule_trigger), 1, NOW())',
      [1 => [$name, 'String']],
    );
    return (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  /**
   * @param array<string, string> $spec
   */
  private function insert(array $spec, int $handlerId, int $ruleId): int {
    $this->assertGreaterThan(0, $handlerId, "no handler row in {$spec['handlerTable']}");
    CRM_Core_DAO::executeQuery(
      "INSERT INTO {$spec['table']} (rule_id, {$spec['handlerColumn']}, {$spec['paramsColumn']}) VALUES (%1, %2, %3)",
      [1 => [$ruleId, 'Integer'], 2 => [$handlerId, 'Integer'], 3 => [self::UNTOUCHED, 'String']],
    );
    return (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  /**
   * @param array<string, string> $spec
   */
  private function storedParams(array $spec, int $id): string {
    return (string) CRM_Core_DAO::singleValueQuery(
      "SELECT {$spec['paramsColumn']} FROM {$spec['table']} WHERE id = %1",
      [1 => [$id, 'Integer']],
    );
  }

  /**
   * Opens an edit link the way a browser request does: the route must lead to
   * $formClass, and the form's own preProcess() reads the row id from the query.
   */
  private function open(string $url, string $formClass): CRM_Core_Form {
    $url = html_entity_decode($url);
    $this->assertSame(1, preg_match('#civicrm/[a-z0-9_/]+#', $url, $route), $url);
    $this->assertSame($formClass, CRM_Core_Menu::get($route[0])['page_callback'] ?? NULL, $url);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $_GET = $_REQUEST = $query;
    try {
      $form = new $formClass();
      $this->assertInstanceOf(CRM_Core_Form::class, $form);
      $form->preProcess();
      $form->buildQuickForm();
    }
    finally {
      $_GET = $_REQUEST = [];
    }
    return $form;
  }

  private function firstOption(CRM_Core_Form $form, string $element): string {
    $select = $form->getElement($element);
    $this->assertInstanceOf(HTML_QuickForm_select::class, $select);
    $values = array_filter(array_map(
      static fn (array $o): string => (string) ($o['attr']['value'] ?? ''),
      $select->_options,
    ));
    $this->assertNotEmpty($values, "{$element} has no options");
    return (string) reset($values);
  }

  /**
   * @param array<string, string> $spec
   * @param array<string, mixed> $submitted
   * @param array<string, mixed> $expected
   */
  private function assertRoundTrip(
    array $spec,
    string $handlerClass,
    string $formClass,
    array $submitted,
    array $expected,
  ): void {
    $handlerId = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM {$spec['handlerTable']} WHERE class_name = %1",
      [1 => [$handlerClass, 'String']],
    );
    $id = $this->insert($spec, $handlerId, $this->rule('herald_test_' . $formClass));

    $url = (string) (new $handlerClass())->getExtraDataInputUrl($id);

    $form = $this->open($url, $formClass);
    $template = ($submitted['template'] ?? NULL) === self::FIRST_TEMPLATE
      ? $this->firstOption($form, 'template')
      : NULL;
    $form->_submitValues = array_replace($submitted, array_filter(['template' => $template]));
    $expected = array_replace($expected, array_filter(['template' => $template]));
    $form->postProcess();

    $saved = unserialize($this->storedParams($spec, $id), ['allowed_classes' => FALSE]);
    $this->assertIsArray($saved);
    $kept = array_intersect_key($saved, $expected);
    ksort($kept);
    ksort($expected);
    $this->assertSame($expected, $kept);

    $defaults = $this->open($url, $formClass)->setDefaultValues();
    foreach ($expected as $key => $value) {
      $shown = is_array($value) ? json_decode((string) $defaults[$key], associative: TRUE) : $defaults[$key] ?? NULL;
      $this->assertEquals($value, $shown, "{$formClass}: {$key} not shown again");
    }
  }

  /**
   * Shared send-form fields: submitted values and what they are saved as.
   *
   * @return array{0: array<string, mixed>, 1: array<string, mixed>}
   */
  private function sendFields(): array {
    return [
      [
        'delivery_mode' => 'addresses',
        'email_addresses' => 'ops@example.org',
        'respect_suspension' => '1',
        'channel' => 'email',
        'template' => self::FIRST_TEMPLATE,
        'chat_id' => '-100',
        'parse_mode' => 'MarkdownV2',
        'disable_notification' => '1',
        'aggregate_minutes' => '15',
        'channel_config' => '{"email_subject": "Hi"}',
      ],
      [
        'delivery_mode' => 'addresses',
        'email_addresses' => 'ops@example.org',
        'respect_suspension' => TRUE,
        'channel' => 'email',
        'template' => self::FIRST_TEMPLATE,
        'chat_id' => '-100',
        'parse_mode' => 'MarkdownV2',
        'disable_notification' => TRUE,
        'aggregate_minutes' => 15,
        'channel_config' => ['email_subject' => 'Hi'],
      ],
    ];
  }

  public function testNotificationForm(): void {
    [$submitted, $expected] = $this->sendFields();
    $this->assertRoundTrip(
      self::ACTION,
      CRM_Herald_CivirulesAdapter_Action_Notification::class,
      CRM_Herald_CivirulesAdapter_Form_SendNotification::class,
      $submitted + ['scope_entity' => 'Event', 'vars' => '{"team": "ops"}'],
      $expected + ['scope_entity' => 'Event', 'vars' => ['team' => 'ops']],
    );
  }

  public function testContributionAlertForm(): void {
    [$submitted, $expected] = $this->sendFields();
    $this->assertRoundTrip(
      self::ACTION,
      CRM_Herald_CivirulesAdapter_Action_ContributionAlert::class,
      CRM_Herald_CivirulesAdapter_Form_SendContributionAlert::class,
      $submitted + ['campaign_id' => '5', 'goal' => '1000', 'campaign_title' => 'Spring'],
      $expected
      + ['campaign_id' => 5, 'goal' => 1000.0, 'campaign_title' => 'Spring', 'include_status_transition' => FALSE],
    );
  }

  public function testContributionDigestForm(): void {
    [$submitted, $expected] = $this->sendFields();
    $this->assertRoundTrip(
      self::ACTION,
      CRM_Herald_CivirulesAdapter_Action_ContributionDigest::class,
      CRM_Herald_CivirulesAdapter_Form_SendContributionDigest::class,
      $submitted
      + [
        'period' => 'last_7_days',
        'campaign_id' => '5',
        'financial_type_id' => '2',
        'source_pattern' => '%Web%',
        'group_by' => 'line_item',
        'show_pending' => '1',
        'bucket_count' => '6',
        'bucket_edges' => '[0, 10, 100]',
        'goal' => '1000',
        'campaign_title' => 'Spring',
      ],
      $expected
      + [
        'period' => 'last_7_days',
        'campaign_id' => 5,
        'financial_type_id' => 2,
        'source_pattern' => '%Web%',
        'group_by' => 'line_item',
        'show_pending' => TRUE,
        'show_histogram' => FALSE,
        'bucket_count' => 6,
        'bucket_edges' => '[0, 10, 100]',
        'goal' => 1000.0,
        'campaign_title' => 'Spring',
      ],
    );
  }

  public function testFieldTransitionForm(): void {
    $values = [
      'entity' => 'Membership',
      'field' => 'status_id',
      'from_operator' => '=',
      'from_value' => '2',
      'to_operator' => '=',
      'to_value' => '3',
    ];
    $this->assertRoundTrip(
      self::CONDITION,
      CRM_Herald_CivirulesAdapter_Condition_FieldTransition::class,
      CRM_Herald_CivirulesAdapter_Form_FieldTransition::class,
      $values,
      $values,
    );
  }

  public function testPreviousContributionStatusForm(): void {
    $this->assertRoundTrip(
      self::CONDITION,
      CRM_Herald_CivirulesAdapter_Condition_PreviousContributionStatus::class,
      CRM_Herald_CivirulesAdapter_Form_PreviousContributionStatus::class,
      ['previous_status_id' => '2'],
      ['previous_status_id' => 2],
    );
  }

}
