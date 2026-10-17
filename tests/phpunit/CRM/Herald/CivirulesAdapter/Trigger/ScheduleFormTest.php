<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The trigger's edit link opens its form, which saves the schedule with the
 * moment it was saved and shows it again on reload.
 *
 * @group headless
 */
class CRM_Herald_CivirulesAdapter_Trigger_ScheduleFormTest extends TestCase implements
  HeadlessInterface,
  TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function tearDown(): void {
    CRM_Utils_Time::resetTime();
    $_GET = [];
    $_REQUEST = [];
    parent::tearDown();
  }

  public function testSavesAndShowsTheSchedule(): void {
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule (name, label, trigger_id, is_active)
       VALUES ('herald_test_schedule_form', 'Weekly digest',
         (SELECT id FROM civirule_trigger WHERE name = 'herald_schedule'), 1)",
    );
    $rule = (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    $url = (string) (new CRM_Herald_CivirulesAdapter_Trigger_Schedule())->getExtraDataInputUrl($rule);

    $form = $this->open($url);
    $defaults = $form->setDefaultValues();
    self::assertSame([], $defaults['weekdays']);
    self::assertSame(8, $defaults['hour']);

    CRM_Utils_Time::setTime('2030-01-07 10:00:00');
    $form->_submitValues = ['rule_id' => (string) $rule, 'weekdays' => ['4', '1'], 'hour' => '7'];
    try {
      $form->postProcess();
      self::fail('the form did not redirect back to the rule');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      self::assertStringContainsString('civicrm/civirule/form/rule', (string) ($e->errorData['url'] ?? ''));
    }

    $saved = unserialize(
      (string) CRM_Core_DAO::singleValueQuery('SELECT trigger_params FROM civirule_rule WHERE id = %1', [
        1 => [$rule, 'Integer'],
      ]),
      ['allowed_classes' => FALSE],
    );
    self::assertIsArray($saved);
    self::assertSame([1, 4], $saved['weekdays']);
    self::assertSame(7, $saved['hour']);
    self::assertEqualsWithDelta(strtotime('2030-01-07 10:00:00'), $saved['since'], 5);

    $defaults = $this->open($url)->setDefaultValues();
    self::assertSame([1, 4], $defaults['weekdays']);
    self::assertSame(7, $defaults['hour']);
  }

  /**
   * Opens the edit link the way a browser request does.
   */
  private function open(string $url): CRM_Herald_CivirulesAdapter_Form_Schedule {
    $url = html_entity_decode($url);
    self::assertSame(1, preg_match('#civicrm/[a-z0-9_/]+#', $url, $route), $url);
    self::assertSame(
      CRM_Herald_CivirulesAdapter_Form_Schedule::class,
      CRM_Core_Menu::get($route[0])['page_callback'] ?? NULL,
    );
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $_GET = $query;
    $_REQUEST = $query;
    $form = new CRM_Herald_CivirulesAdapter_Form_Schedule();
    $form->preProcess();
    $form->buildQuickForm();
    return $form;
  }

}
