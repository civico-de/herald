<?php

declare(strict_types = 1);

namespace Civi\Herald\Preflight;

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use CRM_Herald_ExtensionUtil as E;
use PHPUnit\Framework\TestCase;

/**
 * {@see DeployGate} in the deploy sequence rules-disable → preflight → rules-reenable →
 * note-foreign-rules,
 * {@see RuleHandlers} trigger resolution and {@see HandlerContract}.
 *
 * @group headless
 */
class DeployGateTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    $this->removeMarker();
  }

  protected function tearDown(): void {
    $this->removeMarker();
    parent::tearDown();
  }

  public function testRegisteredHandlersComeFromTheManifests(): void {
    $handlers = RuleHandlers::registered();
    self::assertNotEmpty($handlers);
    foreach ($handlers as $handler) {
      self::assertContains($handler['kind'], ['condition', 'action']);
      self::assertStringStartsWith('CRM_Herald_CivirulesAdapter_', $handler['class']);
    }
  }

  public function testDeploySequenceChecksTheDisabledHeraldRulesAndReenablesThem(): void {
    $rule = $this->rule($this->triggerId('changed_event'), active: TRUE);
    $this->addAction($rule, \CRM_Herald_CivirulesAdapter_Action_Notification::class);

    self::assertStringContainsString('disabled 1 herald rule(s)', $this->script('rules-disable.php'));
    self::assertFalse($this->isActive($rule));
    self::assertStringStartsWith(\Civi::paths()->getPath('[civicrm.private]/'), DeployGate::marker());
    self::assertSame([$rule], DeployGate::markedRuleIds());
    $gated = RuleHandlers::ofRules(DeployGate::markedRuleIds());
    self::assertSame([$rule], $gated['trigger:CRM_CivirulesPostTrigger_Event']['rule_ids'] ?? NULL);
    self::assertArrayNotHasKey('trigger:CRM_CivirulesPostTrigger_Event', RuleHandlers::active());

    self::assertStringContainsString('[herald preflight] OK', $this->script('preflight-rules.php'));
    self::assertStringContainsString("re-enabled 1 herald rule(s): {$rule}", $this->script('rules-reenable.php'));
    self::assertTrue($this->isActive($rule));
    self::assertFileDoesNotExist(DeployGate::marker());
  }

  public function testABrokenHandlerInADisabledHeraldRuleFailsTheGate(): void {
    $rule = $this->rule($this->triggerId('changed_event'), active: TRUE);
    $this->addCondition($rule, 'CRM_Herald_NoSuchCondition');
    $this->script('rules-disable.php');

    $message = $this->gateFailure();
    self::assertStringContainsString(
      "rule {$rule}: condition CRM_Herald_NoSuchCondition — class not found (autoload failed)",
      $message,
    );
    self::assertStringContainsString("herald's rules {$rule} stay disabled", $message);
    self::assertStringContainsString('cv scr ' . E::path('bin/rules-reenable.php'), $message);
    self::assertFalse($this->isActive($rule));
  }

  public function testABrokenTriggerOfADisabledHeraldRuleFailsTheGate(): void {
    $trigger = $this->trigger(['cron' => TRUE]);
    $rule = $this->rule($trigger, active: TRUE);
    $this->addAction($rule, \CRM_Herald_CivirulesAdapter_Action_Notification::class);
    $this->script('rules-disable.php');

    self::assertStringContainsString(
      "rule {$rule}: trigger (no class) — trigger {$trigger} names no class and is not a post trigger",
      $this->gateFailure(),
    );
  }

  public function testAMarkerThatCannotBeWrittenDisablesNoRule(): void {
    $rule = $this->rule($this->triggerId('changed_event'), active: TRUE);
    $this->addAction($rule, \CRM_Herald_CivirulesAdapter_Action_Notification::class);
    mkdir(DeployGate::marker());

    try {
      $this->script('rules-disable.php');
      self::fail('rules-disable.php wrote no marker and still succeeded');
    }
    catch (\Throwable $e) {
      self::assertStringContainsString(DeployGate::marker(), $e->getMessage());
    }
    self::assertTrue($this->isActive($rule));
  }

  public function testAHeraldRuleWithAnInactiveTriggerIsANoteThatDoesNotFailTheGate(): void {
    $rule = $this->rule($this->inactiveTrigger(), active: TRUE);
    $this->addAction($rule, \CRM_Herald_CivirulesAdapter_Action_Notification::class);
    $this->addCondition($rule, 'CRM_Disabled_Extension_Condition');
    $this->script('rules-disable.php');
    self::assertSame([$rule], DeployGate::markedRuleIds());

    self::assertSame(
      "[herald preflight] note, not gating: herald's rules {$rule} have a missing or inactive trigger;"
      . " CiviRules never fires them\n",
      $this->gateNotes(),
    );
  }

  public function testAForeignBrokenActiveRuleIsANoteAfterHeraldsRulesAreReenabled(): void {
    $herald = $this->rule($this->triggerId('changed_event'), active: TRUE);
    $this->addAction($herald, \CRM_Herald_CivirulesAdapter_Action_Notification::class);
    $foreign = $this->rule($this->triggerId('changed_event'), active: TRUE);
    $this->addCondition($foreign, 'CRM_Foreign_NoSuchCondition');
    $inactive = $this->rule($this->triggerId('changed_event'), active: FALSE);
    $this->addCondition($inactive, 'CRM_Foreign_OnlyInAnInactiveRule');
    $this->script('rules-disable.php');

    self::assertSame('', $this->gateNotes());
    $this->script('rules-reenable.php');
    self::assertTrue($this->isActive($herald));

    $note = $this->otherNotes();
    self::assertStringContainsString('not gating', $note);
    self::assertStringContainsString(
      "rule {$foreign}: condition CRM_Foreign_NoSuchCondition — class not found (autoload failed)",
      $note,
    );
    self::assertStringNotContainsString('CRM_Foreign_OnlyInAnInactiveRule', $note);
    self::assertStringContainsString('noted:', $this->script('note-foreign-rules.php'));
  }

  public function testAForeignClassWithAParseErrorIsANote(): void {
    $file = tempnam(sys_get_temp_dir(), 'herald');
    self::assertIsString($file);
    file_put_contents($file, "<?php\nclass CRM_Foreign_ParseErrorCondition {\n");
    $autoload = static function (string $class) use ($file): void {
      if ($class === 'CRM_Foreign_ParseErrorCondition') {
        require $file;
      }
    };
    spl_autoload_register($autoload);
    try {
      $rule = $this->rule($this->triggerId('changed_event'), active: TRUE);
      $this->addCondition($rule, 'CRM_Foreign_ParseErrorCondition');
      self::assertStringContainsString(
        "rule {$rule}: condition CRM_Foreign_ParseErrorCondition — class failed to load: ParseError: ",
        $this->otherNotes(),
      );
    }
    finally {
      spl_autoload_unregister($autoload);
      unlink($file);
    }
  }

  public function testAHealthyInstallWritesNoNote(): void {
    $deactivated = $this->rule($this->inactiveTrigger(), active: TRUE);
    $this->addCondition($deactivated, 'CRM_Disabled_Extension_Condition');

    $notes = fopen('php://memory', 'w+');
    self::assertIsResource($notes);
    self::assertSame(count(RuleHandlers::registered()), DeployGate::run($notes));
    DeployGate::noteOthers($notes);
    rewind($notes);
    self::assertSame('', stream_get_contents($notes));
  }

  public function testTriggerResolutionMirrorsCiviRules(): void {
    $post = $this->rule($this->trigger([]), active: TRUE);
    $cronTrigger = $this->trigger(['cron' => TRUE]);
    $cron = $this->rule($cronTrigger, active: TRUE);
    $inactive = $this->rule($this->inactiveTrigger(), active: TRUE);
    $this->addCondition($inactive, 'CRM_Disabled_Extension_Condition');
    $classed = $this->rule($this->trigger(['cron' => TRUE, 'class_name' => 'CRM_Foreign_CronTrigger']), active: TRUE);

    $handlers = RuleHandlers::ofRules([$post, $cron, $inactive, $classed]);
    self::assertSame([$post], $handlers['trigger:CRM_Civirules_Trigger_Post']['rule_ids'] ?? NULL);
    self::assertSame([$classed], $handlers['trigger:CRM_Foreign_CronTrigger']['rule_ids'] ?? NULL);
    $noClass = "trigger {$cronTrigger} names no class and is not a post trigger";
    self::assertSame([$cron], $handlers["trigger:{$noClass}"]['rule_ids'] ?? NULL);
    self::assertSame($noClass, $handlers["trigger:{$noClass}"]['problem']);
    foreach ([...$handlers, ...RuleHandlers::active()] as $handler) {
      self::assertNotContains($inactive, $handler['rule_ids']);
    }
    self::assertSame([$inactive], RuleHandlers::dormant([$post, $cron, $inactive, $classed]));
  }

  public function testInspectMirrorsHowCiviRulesResolvesAHandler(): void {
    self::assertNull(HandlerContract::inspect('action', \CRM_Herald_CivirulesAdapter_Action_Notification::class));
    self::assertSame(
      'not instantiable (abstract, interface, or an abstract method left unimplemented)',
      HandlerContract::inspect('action', \CRM_Herald_CivirulesAdapter_Action_AbstractSend::class),
    );
    self::assertSame(
      'does not extend CRM_Civirules_Condition',
      HandlerContract::inspect('condition', \CRM_Herald_CivirulesAdapter_Action_Notification::class),
    );
    self::assertSame('class not found (autoload failed)', HandlerContract::inspect('condition', 'CRM_Herald_Nope'));
    self::assertSame("unknown handler kind 'widget'", HandlerContract::inspect('widget', DeployGate::class));
    self::assertSame(
      'constructor requires arguments; CiviRules instantiates handlers without any',
      HandlerContract::violation(new \ReflectionClass(\ReflectionClass::class), \Reflector::class),
    );
    $throwing = new class(boom: FALSE) extends \ArrayObject {

      public function __construct(bool $boom = TRUE) {
        parent::__construct();
        if ($boom) {
          throw new \RuntimeException('boom');
        }
      }

    };
    self::assertSame(
      'constructor threw RuntimeException: boom',
      HandlerContract::violation(new \ReflectionClass($throwing), \ArrayObject::class),
    );
  }

  private function gateNotes(): string {
    $notes = fopen('php://memory', 'w+');
    self::assertIsResource($notes);
    DeployGate::run($notes);
    rewind($notes);
    return (string) stream_get_contents($notes);
  }

  private function otherNotes(): string {
    $notes = fopen('php://memory', 'w+');
    self::assertIsResource($notes);
    DeployGate::noteOthers($notes);
    rewind($notes);
    return (string) stream_get_contents($notes);
  }

  private function gateFailure(): string {
    $notes = fopen('php://memory', 'w+');
    self::assertIsResource($notes);
    try {
      DeployGate::run($notes);
    }
    catch (\CRM_Core_Exception $e) {
      return $e->getMessage();
    }
    self::fail('the gate passed');
  }

  private function script(string $name): string {
    ob_start();
    try {
      require dirname(__DIR__, 5) . '/bin/' . $name;
    }
    finally {
      $output = (string) ob_get_clean();
    }
    return $output;
  }

  private function removeMarker(): void {
    $marker = DeployGate::marker();
    match (TRUE) {
      is_dir($marker) => rmdir($marker),
      is_file($marker) => unlink($marker),
      default => NULL,
    };
  }

  private function isActive(int $ruleId): bool {
    return (
      CiviRulesRule::get(checkPermissions: FALSE)
        ->addWhere('id', '=', $ruleId)
        ->execute()
        ->single()['is_active'] === TRUE
    );
  }

  private function triggerId(string $name): int {
    return (int) CiviRulesTrigger::get(checkPermissions: FALSE)
      ->addWhere('name', '=', $name)
      ->execute()
      ->single()['id'];
  }

  /**
   * @param array<string, mixed> $values
   */
  private function trigger(array $values): int {
    return (int) CiviRulesTrigger::create(checkPermissions: FALSE)
      ->setValues(
        $values
        + [
          'name' => 'herald_test_' . uniqid(),
          'label' => 'herald test trigger',
          'object_name' => 'Event',
          'op' => 'edit',
          'cron' => FALSE,
          'is_active' => TRUE,
        ],
      )
      ->execute()
      ->single()['id'];
  }

  private function inactiveTrigger(): int {
    return $this->trigger(['class_name' => 'CRM_Civirules_Trigger_Post', 'is_active' => FALSE]);
  }

  private function addCondition(int $ruleId, string $class): void {
    $conditionId = CiviRulesCondition::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_' . md5($class),
        'label' => $class,
        'class_name' => $class,
        'is_active' => TRUE,
      ])
      ->execute()
      ->single()['id'];
    CiviRulesRuleCondition::create(checkPermissions: FALSE)
      ->setValues(['rule_id' => $ruleId, 'condition_id' => $conditionId, 'is_active' => TRUE])
      ->execute();
  }

  private function addAction(int $ruleId, string $class): void {
    $actionId = CiviRulesAction::get(checkPermissions: FALSE)
      ->addWhere('class_name', '=', $class)
      ->execute()
      ->single()['id'];
    CiviRulesRuleAction::create(checkPermissions: FALSE)
      ->setValues(['rule_id' => $ruleId, 'action_id' => $actionId, 'is_active' => TRUE])
      ->execute();
  }

  private function rule(int $triggerId, bool $active): int {
    return (int) CiviRulesRule::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_' . uniqid(),
        'label' => 'herald test rule',
        'trigger_id' => $triggerId,
        'is_active' => $active,
      ])
      ->execute()
      ->single()['id'];
  }

}
