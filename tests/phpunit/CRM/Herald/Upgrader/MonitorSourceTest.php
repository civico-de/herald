<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Upgrade 1018 moves the monitor onto System.check: state and snoozes of the
 * old check names go, expected check names become extension keys.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_MonitorSourceTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testStateAndSnoozesGoAndExpectedNamesBecomeExtensionKeys(): void {
    Civi::settings()->set('herald_monitor_state', ['pulse.cron' => ['status' => 'fail', 'consecutive_fails' => 2]]);
    Civi::settings()->set('herald_monitor_snooze', ['sentinel.login' => '2099-01-01T00:00:00']);
    Civi::settings()->set('herald_monitor_expected', [
      'pulse.cron',
      'pulse.mail',
      'warden.settings',
      'herald.buffer_backlog',
      'backup.offsite',
      '',
      5,
    ]);

    $ctx = new CRM_Queue_TaskContext();
    $ctx->log = CRM_Core_Error::createDebugLogger();
    $this->assertTrue(CRM_Herald_Upgrader::_queueAdapter($ctx, 'herald', 'upgrade_1018'));

    $this->assertSame([], Civi::settings()->get('herald_monitor_state'));
    $this->assertSame([], Civi::settings()->get('herald_monitor_snooze'));
    $this->assertSame(['pulse', 'warden', 'herald', 'backup'], Civi::settings()->get('herald_monitor_expected'));
  }

  public function testAnEmptyExpectedListStaysEmpty(): void {
    Civi::settings()->set('herald_monitor_expected', []);
    $this->assertSame(0, CRM_Herald_Upgrader_MonitorSource::migrate());
    $this->assertSame([], Civi::settings()->get('herald_monitor_expected'));
  }

}
