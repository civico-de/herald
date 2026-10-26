<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Api4\Herald;
use Civi\Herald\Monitor\Monitor;
use Civi\Herald\Monitor\Threshold;
use Civi\Test\HookInterface;

/**
 * Herald.getMonitorChecks / saveMonitorChecks: the monitor screen on the real
 * System.check, fed by this test's hook_civicrm_check.
 *
 * @group headless
 */
class MonitorChecksTest extends AdminTestCase implements HookInterface {

  private const NOW = 1_790_000_000;

  private const STATE = [
    'herald_test_gone' => [
      'status' => 'fail',
      'severity' => 4,
      'detail' => ['old'],
      'consecutive_fails' => 3,
      'consecutive_oks' => 0,
      'notified' => TRUE,
      'last_notified' => self::NOW - 600,
    ],
  ];

  protected function setUp(): void {
    parent::setUp();
    \CRM_Utils_Time::setTime(date('Y-m-d H:i:s', self::NOW));
    \Civi::settings()->set(Monitor::STATE_SETTING, self::STATE);
    \Civi::settings()->set(Threshold::MIN_SETTING, 'warning');
    \Civi::settings()->set(Threshold::EXCEPTIONS_SETTING, ['herald_test_quiet*' => 'critical']);
    \Civi::settings()->set(MonitorChecks::EXPECTED, []);
    \Civi::settings()->set(MonitorChecks::SNOOZE, [
      'herald_test_disk' => '2027-01-15T08:00:00',
      'herald_test_q*' => '',
    ]);
  }

  protected function tearDown(): void {
    \CRM_Utils_Time::resetTime();
    parent::tearDown();
  }

  /**
   * @param array<int, \CRM_Utils_Check_Message> $messages
   */
  public function hook_civicrm_check(array &$messages): void {
    $messages[] = new \CRM_Utils_Check_Message('herald_test_disk:/var', '<p>90% full</p>', 'Disk', 'error');
    $messages[] = new \CRM_Utils_Check_Message('herald_test_quiet', 'quiet', 'Quiet', 'error');
    $messages[] = new \CRM_Utils_Check_Message('herald_test_info', 'just so you know', 'Info', 'info');
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private static function checks(): array {
    $screen = self::call('getMonitorChecks');
    self::assertCount(1, $screen);
    self::assertIsArray($screen[0]['checks']);
    return array_column($screen[0]['checks'], NULL, 'key');
  }

  public function testTheScreenCombinesSystemCheckSettingsAndState(): void {
    $screen = self::call('getMonitorChecks')[0];
    $checks = array_column($screen['checks'], NULL, 'key');

    $this->assertSame(
      [
        'key' => 'herald_test_disk',
        'reported' => TRUE,
        'title' => 'Disk',
        'detail' => '/var',
        'severity' => 'error',
        'severity_label' => 'Error',
        'visible' => TRUE,
        'failing' => TRUE,
        'threshold' => 'warning',
        'snoozed' => TRUE,
        'status' => '',
        'consecutive_fails' => 0,
        'consecutive_oks' => 0,
        'notified' => FALSE,
        'last_notified' => NULL,
      ],
      $checks['herald_test_disk'],
    );
    $this->assertFalse($checks['herald_test_quiet']['failing'], 'its exception lifts the threshold above error');
    $this->assertSame('critical', $checks['herald_test_quiet']['threshold']);
    $this->assertTrue($checks['herald_test_quiet']['snoozed'], 'a permanent prefix snooze matches');
    $this->assertFalse($checks['herald_test_info']['failing']);

    $gone = $checks['herald_test_gone'];
    $this->assertFalse($gone['reported']);
    $this->assertSame('fail', $gone['status']);
    $this->assertSame(self::NOW - 600, $gone['last_notified']);

    $this->assertSame('warning', $screen['min_severity']);
    $this->assertSame(['herald_test_quiet*' => 'critical'], $screen['severity']);
    $this->assertSame(
      [
        [
          'pattern' => 'herald_test_disk',
          'until' => '2027-01-15T08:00:00',
          'until_ts' => strtotime('2027-01-15T08:00:00'),
        ],
        ['pattern' => 'herald_test_q*', 'until' => '', 'until_ts' => NULL],
      ],
      $screen['snooze'],
    );
    $this->assertSame('notice', $screen['levels'][0]['name']);
    $this->assertStringContainsString('civicrm/a/', $screen['status_url']);
    $this->assertStringNotContainsString(
      '90% full',
      (string) json_encode($screen),
      'message texts stay off the screen',
    );
    $this->assertSame(self::STATE, \Civi::settings()->get(Monitor::STATE_SETTING), 'the screen writes nothing');
  }

  public function testSaveStoresTheSettingsAndLeavesTheState(): void {
    $future = date('Y-m-d\TH:i', self::NOW + 86_400);
    $result = self::call('saveMonitorChecks', [
      'minSeverity' => 'notice',
      'severity' => ['warden_*' => 'error', 'herald_test_info' => 'notice'],
      'snooze' => [
        'herald_test_disk' => '2027-01-15T08:00:00+00:00',
        'herald_test_info' => $future,
        'herald_test_q*' => '',
      ],
      'expected' => ['herald', 'herald', 'org.example.ext'],
    ]);

    $snooze = [
      // Same instant as stored (the site runs in UTC here): the stored string stays.
      'herald_test_disk' => '2027-01-15T08:00:00',
      'herald_test_info' => date('c', (int) strtotime($future)),
      'herald_test_q*' => '',
    ];
    $this->assertSame(
      [[
        'min_severity' => 'notice',
        'severity' => ['warden_*' => 'error', 'herald_test_info' => 'notice'],
        'snooze' => $snooze,
        'expected' => ['herald', 'org.example.ext'],
      ]],
      $result,
    );
    $this->assertSame('notice', \Civi::settings()->get(Threshold::MIN_SETTING));
    $this->assertSame(
      ['warden_*' => 'error', 'herald_test_info' => 'notice'],
      \Civi::settings()->get(Threshold::EXCEPTIONS_SETTING),
    );
    $this->assertSame($snooze, \Civi::settings()->get(MonitorChecks::SNOOZE));
    $this->assertSame(['herald', 'org.example.ext'], \Civi::settings()->get(MonitorChecks::EXPECTED));
    $this->assertSame(self::STATE, \Civi::settings()->get(Monitor::STATE_SETTING));
  }

  public function testRoundTripKeepsTheSettings(): void {
    $screen = self::call('getMonitorChecks')[0];
    self::call('saveMonitorChecks', [
      'minSeverity' => $screen['min_severity'],
      'severity' => $screen['severity'],
      'snooze' => array_column($screen['snooze'], 'until', 'pattern'),
      'expected' => $screen['expected'],
    ]);
    $this->assertSame(
      ['herald_test_disk' => '2027-01-15T08:00:00', 'herald_test_q*' => ''],
      \Civi::settings()->get(MonitorChecks::SNOOZE),
    );
    $this->assertSame(['herald_test_quiet*' => 'critical'], \Civi::settings()->get(Threshold::EXCEPTIONS_SETTING));
  }

  public function testInvalidInputIsRejected(): void {
    $this->assertRejected(
      'saveMonitorChecks',
      [
        'minSeverity' => 'info',
        'severity' => ['bad key' => 'error', 'ok_key' => 'loud'],
        'snooze' => ['a' => 'next blue moon', 'b' => date('c', self::NOW - 60), 'c' => 5, 'x:y' => ''],
        'expected' => [' padded', ''],
      ],
      [
        'notice',
        'bad key',
        '"ok_key" must be one of',
        'The snooze of "a" is not a date and time.',
        'The snooze of "b" must end in the future.',
        'The snooze of "c" is not a date and time.',
        '"x:y" is not a check key',
        'by its key',
        'by its key',
      ],
    );
    $this->assertSame('warning', \Civi::settings()->get(Threshold::MIN_SETTING));
    $this->assertSame([], \Civi::settings()->get(MonitorChecks::EXPECTED));
  }

  public function testTheScreenListsTheStateKeyEvenWhenNothingReportsIt(): void {
    $this->assertArrayHasKey('herald_test_gone', self::checks());
  }

  public function testOnlyAdministratorsMayUseTheScreen(): void {
    $this->assertAdminOnly(static fn () => Herald::getMonitorChecks()->execute());
    $this->assertAdminOnly(static fn () => Herald::saveMonitorChecks()->setMinSeverity('warning')->execute());
    $this->assertAdminOnly(static fn () => Herald::monitorPreview()->execute());
  }

}
