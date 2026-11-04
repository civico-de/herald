<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Upgrade 1019 deletes the logged copies of the monitor state and nothing else.
 *
 * Not transactional: CREATE TABLE commits implicitly, so the test drops its log table itself.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_MonitorLogTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function tearDown(): void {
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS log_civicrm_setting');
    parent::tearDown();
  }

  public function testOnlyTheMonitorStateCopiesGo(): void {
    CRM_Core_DAO::executeQuery(
      'CREATE TABLE log_civicrm_setting (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))',
    );
    CRM_Core_DAO::executeQuery(
      'INSERT INTO log_civicrm_setting (name) SELECT %1 FROM civicrm_option_value LIMIT 25',
      [1 => ['herald_monitor_state', 'String']],
    );
    CRM_Core_DAO::executeQuery(
      "INSERT INTO log_civicrm_setting (name) VALUES ('herald_monitor_snooze'), ('resCacheCode')",
    );
    $monitorRows = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM log_civicrm_setting WHERE name = 'herald_monitor_state'",
    );
    $this->assertSame(25, $monitorRows);

    $ctx = new CRM_Queue_TaskContext();
    $ctx->log = CRM_Core_Error::createDebugLogger();
    $this->assertTrue(CRM_Herald_Upgrader::_queueAdapter($ctx, 'herald', 'upgrade_1019'));

    $left = CRM_Core_DAO::executeQuery('SELECT name FROM log_civicrm_setting ORDER BY name')->fetchMap('name', 'name');
    $this->assertSame(['herald_monitor_snooze', 'resCacheCode'], array_values($left));
  }

  public function testTheDeleteRunsInBatchesUntilNoneIsLeft(): void {
    CRM_Core_DAO::executeQuery(
      'CREATE TABLE log_civicrm_setting (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))',
    );
    CRM_Core_DAO::executeQuery(
      'INSERT INTO log_civicrm_setting (name) SELECT %1 FROM civicrm_option_value LIMIT 25',
      [1 => ['herald_monitor_state', 'String']],
    );
    $this->assertSame(25, CRM_Herald_Upgrader_MonitorLog::purge(10));
    $this->assertSame(0, (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM log_civicrm_setting'));
  }

  public function testWithoutLoggingThereIsNothingToDelete(): void {
    $this->assertSame(0, CRM_Herald_Upgrader_MonitorLog::purge());
  }

}
