<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Api4\Herald;
use Civi\Core\Event\GenericHookEvent;
use Civi\Herald\Monitor\Monitor;

/**
 * Herald.getMonitorChecks / saveMonitorChecks: the monitor screen.
 *
 * @group headless
 */
class MonitorChecksTest extends AdminTestCase {

  private const NOW = 1_790_000_000;

  private const STATE = [
    'mail.queue' => [
      'status' => 'fail',
      'detail' => 'private detail text',
      'consecutive_fails' => 3,
      'consecutive_oks' => 0,
      'notified' => TRUE,
      'last_notified' => self::NOW - 600,
    ],
  ];

  /**
   * @var callable|null
   */
  private $listener = NULL;

  protected function setUp(): void {
    parent::setUp();
    \CRM_Utils_Time::setTime(date('Y-m-d H:i:s', self::NOW));
    $this->listener = static function (GenericHookEvent $e): void {
      $e->checks['mail.queue'] = static fn () => throw new \LogicException('the listing must not run checks');
      $e->checks['disk.space'] = static fn () => throw new \LogicException('the listing must not run checks');
    };
    \Civi::dispatcher()->addListener('hook_civicrm_heraldChecks', $this->listener);
    \Civi::settings()->set(Monitor::STATE_SETTING, self::STATE);
    \Civi::settings()->set(MonitorChecks::EXPECTED, ['mail.queue', 'backup.offsite']);
    \Civi::settings()->set(MonitorChecks::SNOOZE, ['disk.space' => '2027-01-15T08:00:00']);
  }

  protected function tearDown(): void {
    if ($this->listener !== NULL) {
      \Civi::dispatcher()->removeListener('hook_civicrm_heraldChecks', $this->listener);
    }
    \CRM_Utils_Time::resetTime();
    parent::tearDown();
  }

  public function testListingCombinesHookSettingsAndState(): void {
    $rows = array_column(self::call('getMonitorChecks'), NULL, 'name');

    $this->assertSame(
      ['backup.offsite', 'disk.space', 'mail.queue'],
      array_values(array_intersect(
        array_keys($rows),
        ['backup.offsite', 'disk.space', 'mail.queue'],
      )),
    );
    $this->assertSame(
      [
        'name' => 'mail.queue',
        'provided' => TRUE,
        'expected' => TRUE,
        'snoozed' => FALSE,
        'snoozed_until' => '',
        'snoozed_until_ts' => NULL,
        'status' => 'fail',
        'consecutive_fails' => 3,
        'consecutive_oks' => 0,
        'notified' => TRUE,
        'last_notified' => self::NOW - 600,
      ],
      $rows['mail.queue'],
    );
    $this->assertFalse($rows['backup.offsite']['provided']);
    $this->assertSame('', $rows['backup.offsite']['status']);
    $this->assertTrue($rows['disk.space']['snoozed']);
    $this->assertSame(strtotime('2027-01-15T08:00:00'), $rows['disk.space']['snoozed_until_ts']);
    $this->assertStringNotContainsString('private detail text', (string) json_encode($rows));
  }

  public function testSaveStoresBothSettingsAndLeavesTheState(): void {
    $future = date('Y-m-d\TH:i', self::NOW + 86_400);
    $result = self::call('saveMonitorChecks', [
      'expected' => ['disk.space', 'disk.space', 'backup.offsite'],
      'snooze' => ['disk.space' => '2027-01-15T08:00:00+00:00', 'mail.queue' => $future, 'backup.offsite' => ''],
    ]);

    $snooze = [
      // Same instant as stored (the site runs in UTC here): the stored string stays.
      'disk.space' => '2027-01-15T08:00:00',
      'mail.queue' => date('c', (int) strtotime($future)),
    ];
    $this->assertSame([['expected' => ['disk.space', 'backup.offsite'], 'snooze' => $snooze]], $result);
    $this->assertSame(['disk.space', 'backup.offsite'], \Civi::settings()->get(MonitorChecks::EXPECTED));
    $this->assertSame($snooze, \Civi::settings()->get(MonitorChecks::SNOOZE));
    $this->assertSame(self::STATE, \Civi::settings()->get(Monitor::STATE_SETTING));
  }

  public function testRoundTripKeepsTheSettings(): void {
    self::call('saveMonitorChecks', [
      'expected' => \Civi::settings()->get(MonitorChecks::EXPECTED),
      'snooze' => \Civi::settings()->get(MonitorChecks::SNOOZE),
    ]);
    $this->assertSame(['mail.queue', 'backup.offsite'], \Civi::settings()->get(MonitorChecks::EXPECTED));
    $this->assertSame(['disk.space' => '2027-01-15T08:00:00'], \Civi::settings()->get(MonitorChecks::SNOOZE));
  }

  public function testInvalidInputIsRejected(): void {
    $this->assertRejected(
      'saveMonitorChecks',
      [
        'expected' => [' padded', ''],
        'snooze' => ['a' => 'next blue moon', 'b' => date('c', self::NOW - 60), 'c' => 5],
      ],
      [
        'without leading or trailing spaces',
        'without leading or trailing spaces',
        'The snooze of "a" is not a date and time.',
        'The snooze of "b" must end in the future.',
        'The snooze of "c" is not a date and time.',
      ],
    );
    $this->assertSame(['mail.queue', 'backup.offsite'], \Civi::settings()->get(MonitorChecks::EXPECTED));
  }

  public function testOnlyAdministratorsMayUseTheScreen(): void {
    $this->assertAdminOnly(static fn () => Herald::getMonitorChecks()->execute());
    $this->assertAdminOnly(static fn () => Herald::saveMonitorChecks()->execute());
  }

}
