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

  private const TRANSITION = [
    'entity' => 'Membership',
    'field' => 'status_id',
    'from_operator' => '=',
    'from_value' => '2',
    'to_operator' => '=',
    'to_value' => '3',
  ];

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
    $_GET = $query;
    $_REQUEST = $query;
    try {
      $form = new $formClass();
      $this->assertInstanceOf(CRM_Core_Form::class, $form);
      $form->preProcess();
      $form->buildQuickForm();
    }
    finally {
      $_GET = [];
      $_REQUEST = [];
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

  /**
   * @return array<string, array{0: array<string, string>, 1: string}>
   */
  public static function unsavableValues(): array {
    return [
      'channel_config that is no JSON' => [
        ['channel_config' => '{"chat_id": '],
        'channel_config must be a JSON object',
      ],
      'channel_config that is a list' => [
        ['channel_config' => '[{"chat_id": "-1"}]'],
        'channel_config must be a JSON object',
      ],
      'vars that are a list' => [['vars' => '["ops"]'], 'vars must be a JSON object'],
      'negative window' => [['aggregate_minutes' => '-5'], 'must be at least 0'],
      'window that is no number' => [['aggregate_minutes' => 'soon'], 'must be a whole number'],
      'group mode without a group' => [['delivery_mode' => 'group'], 'needs "Group"'],
      'address mode without addresses' => [['email_addresses' => ' '], 'needs "Email addresses"'],
    ];
  }

  /**
   * @dataProvider unsavableValues
   * @param array<string, string> $change
   */
  public function testTheFormNamesWhatItCannotSave(array $change, string $error): void {
    $form = $this->sendForm();
    $form->_submitValues = array_replace($this->validSubmission($form), $change);
    $form->_flagSubmitted = TRUE;

    $this->assertFalse($form->validate());
    $this->assertStringContainsString($error, (string) ($form->_errors['_qf_default'] ?? ''));
  }

  public function testAValidSubmissionPasses(): void {
    $form = $this->sendForm();
    $form->_submitValues = $this->validSubmission($form);
    $form->_flagSubmitted = TRUE;

    $this->assertTrue($form->validate(), json_encode($form->_errors, JSON_THROW_ON_ERROR));
  }

  private function sendForm(): CRM_Core_Form {
    $handlerId = (int) CRM_Core_DAO::singleValueQuery(
      'SELECT id FROM civirule_action WHERE class_name = %1',
      [1 => [CRM_Herald_CivirulesAdapter_Action_Notification::class, 'String']],
    );
    $id = $this->insert(self::ACTION, $handlerId, $this->rule('herald_test_validation'));
    return $this->open(
      (string) (new CRM_Herald_CivirulesAdapter_Action_Notification())->getExtraDataInputUrl($id),
      CRM_Herald_CivirulesAdapter_Form_SendNotification::class,
    );
  }

  /**
   * @return array<string, string>
   */
  private function validSubmission(CRM_Core_Form $form): array {
    [$submitted] = $this->sendFields();
    return ['template' => $this->firstOption($form, 'template'), 'vars' => '{"team": "ops"}'] + $submitted;
  }

  public function testDigestForm(): void {
    [$submitted, $expected] = $this->sendFields();
    // A digest's blocks bring their own templates.
    unset($submitted['template'], $expected['template']);
    \Civi\Api4\HeraldDigest::create(checkPermissions: FALSE)
      ->setValues(['name' => 'herald_test_round_trip', 'title' => 'Round trip'])
      ->execute();
    $this->assertRoundTrip(
      self::ACTION,
      CRM_Herald_CivirulesAdapter_Action_Digest::class,
      CRM_Herald_CivirulesAdapter_Form_SendDigest::class,
      $submitted + ['digest' => 'herald_test_round_trip'],
      $expected + ['digest' => 'herald_test_round_trip'],
    );
  }

  /**
   * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
   */
  public static function uncheckableTransitions(): array {
    return [
      'unknown entity' => [['entity' => 'Membershipp'], 'entity', '"Membershipp" cannot be checked'],
      'unknown field' => [['field' => 'statusid'], 'field', 'Membership has no field "statusid".'],
      'unknown suffix' => [['field' => 'status_id:nam'], 'field', 'Membership.status_id has no option suffix ":nam".'],
      'suffix without options' => [
        ['field' => 'join_date:name'],
        'field',
        'Membership.join_date has no option suffix ":name".',
      ],
      'wrong case' => [['field' => 'Status_ID'], 'field', 'Membership has no field "Status_ID".'],
      'search filter' => [
        ['entity' => 'Contact', 'field' => 'groups:name'],
        'field',
        'Contact.groups is a search filter and holds no value.',
      ],
      'unknown operator' => [['to_operator' => '=='], 'to_value', 'Unknown operator "==".'],
      'ordering without a number' => [
        ['from_operator' => '>=', 'from_value' => 'Pending'],
        'from_value',
        '">=" needs a number',
      ],
    ];
  }

  /**
   * @dataProvider uncheckableTransitions
   * @param array<string, string> $change
   */
  public function testTheConditionFormNamesWhatItCannotCheck(array $change, string $element, string $error): void {
    $form = $this->transitionForm();
    $form->_submitValues = array_replace(self::TRANSITION, $change);
    $form->_flagSubmitted = TRUE;

    $this->assertFalse($form->validate());
    $this->assertStringContainsString($error, (string) ($form->_errors[$element] ?? ''));
  }

  /**
   * @return array<string, array{array<string, string>}>
   */
  public static function checkableTransitions(): array {
    return [
      'core field' => [[]],
      'option suffix' => [['field' => 'status_id:name', 'from_value' => 'Grace', 'to_value' => 'Expired']],
      'custom field' => [['entity' => 'Contact', 'field' => 'herald_preferences.preferred_channel']],
      'ordering with a number' => [['from_operator' => '>=', 'from_value' => ' 2 ']],
      'emptiness without a value' => [['to_operator' => 'not_empty', 'to_value' => '']],
    ];
  }

  /**
   * @dataProvider checkableTransitions
   * @param array<string, string> $change
   */
  public function testACheckableTransitionPasses(array $change): void {
    $form = $this->transitionForm();
    $form->_submitValues = array_replace(self::TRANSITION, $change);
    $form->_flagSubmitted = TRUE;

    $this->assertTrue($form->validate(), json_encode($form->_errors, JSON_THROW_ON_ERROR));
  }

  private function transitionForm(): CRM_Core_Form {
    $handlerId = (int) CRM_Core_DAO::singleValueQuery(
      'SELECT id FROM civirule_condition WHERE class_name = %1',
      [1 => [CRM_Herald_CivirulesAdapter_Condition_FieldTransition::class, 'String']],
    );
    $id = $this->insert(self::CONDITION, $handlerId, $this->rule('herald_test_transition'));
    return $this->open(
      (string) (new CRM_Herald_CivirulesAdapter_Condition_FieldTransition())->getExtraDataInputUrl($id),
      CRM_Herald_CivirulesAdapter_Form_FieldTransition::class,
    );
  }

  public function testFieldTransitionForm(): void {
    $values = self::TRANSITION;
    $this->assertRoundTrip(
      self::CONDITION,
      CRM_Herald_CivirulesAdapter_Condition_FieldTransition::class,
      CRM_Herald_CivirulesAdapter_Form_FieldTransition::class,
      $values,
      $values,
    );
  }

}
