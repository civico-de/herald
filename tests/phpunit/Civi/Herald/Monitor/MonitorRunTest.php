<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Monitor::run() and preview() against a booted CiviCRM — the thin Civi
 * wrapper around the pure planner (whose state machine is covered in
 * MonitorTest): the settings it reads, snooze housekeeping, the publish call
 * and the state it writes.
 *
 * The System.check rows are faked at the collector boundary, so the run sees
 * exactly the messages a test names. MonitorSystemCheckTest covers the real source.
 *
 * No network is touched: the `ops` route is configured with
 * aggregate_minutes > 0, so Herald.publish parks the alert in the aggregation
 * buffer instead of handing it to a channel — and the buffer rows are what
 * these tests read back.
 *
 * @group headless
 */
class MonitorRunTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private const INFO = 1;

  private const WARNING = 3;

  private const ERROR = 4;

  private const CRITICAL = 5;

  /**
   * @var list<array<string, mixed>>
   */
  private array $rows = [];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \CRM_Core_DAO::executeQuery('DELETE FROM `' . AlertBuffer::TABLE . '`');
    \Civi::settings()->set(Monitor::STATE_SETTING, []);
    \Civi::settings()->set(Monitor::TOPIC_SETTING, 'ops');
    \Civi::settings()->set(ExpectedExtensions::SETTING, []);
    \Civi::settings()->set(Snooze::SETTING, []);
    \Civi::settings()->revert(Threshold::MIN_SETTING);
    \Civi::settings()->revert(Threshold::EXCEPTIONS_SETTING);
    // Deliberately no "default" entry: an alert that resolves to any topic
    // other than "ops" stays unrouted, and the wrapper swallows the failure —
    // which is exactly what makes the topic assertions below meaningful.
    \Civi::settings()->set('herald_topics', [
      'ops' => ['channel' => 'telegram', 'chat_id' => '-1001234', 'aggregate_minutes' => 30],
    ]);
    $this->rows = [];
  }

  private function monitor(): Monitor {
    return new Monitor(new CheckCollector(fn (): array => $this->rows));
  }

  private function message(string $name, int $severity, string $message = '', bool $visible = TRUE): void {
    $this->rows[] = [
      'name' => $name,
      'severity_id' => $severity,
      'title' => "Title of {$name}",
      'message' => $message,
      'is_visible' => (int) $visible,
    ];
  }

  /**
   * @return list<string> Bodies parked in the aggregation buffer.
   */
  private function bufferedBodies(): array {
    $rows = \CRM_Core_DAO::executeQuery('SELECT body FROM `' . AlertBuffer::TABLE . '` ORDER BY id');
    $bodies = [];
    while ($rows->fetch()) {
      $bodies[] = (string) $rows->body;
    }
    return $bodies;
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function state(): array {
    $state = \Civi::settings()->get(Monitor::STATE_SETTING);
    return is_array($state) ? $state : [];
  }

  public function testARunWithoutMessagesAboveTheThresholdPublishesNothing(): void {
    $this->message('checkPhpVersion', self::INFO, 'PHP Up-to-Date');
    $this->message('warden_redirect_uri', self::WARNING);

    $result = $this->monitor()->run();

    $this->assertSame(['checks' => 2, 'failing' => 0, 'transitions' => 0, 'publish_failures' => 0], $result);
    $this->assertSame([], $this->bufferedBodies());
    $this->assertSame([], $this->state(), 'healthy keys leave no state');
  }

  public function testInfoMessagesStayHealthyAtTheLowestThreshold(): void {
    \Civi::settings()->set(Threshold::MIN_SETTING, 'notice');
    $this->message('checkPhpVersion', self::INFO, 'PHP Up-to-Date');
    $this->message('checkMysqlVersion', self::INFO);

    $this->assertSame(0, $this->monitor()->run()['failing']);
    $this->assertSame([], $this->bufferedBodies());
  }

  public function testAFailureIsPublishedWithTitleAndPlainTextAndItsDisappearanceRecovers(): void {
    $this->message('checkOutboundMail', self::WARNING, '<p>Outbound email is <b>disabled</b> &amp; queued.</p>');

    $result = $this->monitor()->run();

    $this->assertSame(1, $result['transitions']);
    $bodies = $this->bufferedBodies();
    $this->assertCount(1, $bodies);
    $this->assertStringContainsString('checkOutboundMail', $bodies[0]);
    $this->assertStringContainsString('Title of checkOutboundMail', $bodies[0]);
    $this->assertStringContainsString(
      'Outbound email is disabled &amp; queued.',
      $bodies[0],
      'markup is dropped, then escaped once',
    );
    $this->assertStringNotContainsString('&lt;b&gt;', $bodies[0]);
    $this->assertSame('fail', $this->state()['checkOutboundMail']['status']);
    $this->assertSame(self::WARNING, $this->state()['checkOutboundMail']['severity']);

    $this->rows = [];
    $this->assertSame(1, $this->monitor()->run()['transitions']);
    $this->assertStringContainsString('ok again', $this->bufferedBodies()[1]);
    $this->assertSame([], $this->state());
  }

  public function testTheSameFailureWithChangingTextStaysSilent(): void {
    $this->message('checkLastCron', self::WARNING, 'Last cron 10:00');
    $this->assertSame(1, $this->monitor()->run()['transitions']);
    foreach (['10:05', '10:10'] as $time) {
      $this->rows = [];
      $this->message('checkLastCron', self::WARNING, "Last cron {$time}");
      $this->assertSame(0, $this->monitor()->run()['transitions']);
    }
    $this->assertCount(1, $this->bufferedBodies());
  }

  public function testAMessageHiddenInCoreChangesTheStateWithoutPublishing(): void {
    $this->message('checkOutboundMail', self::ERROR, visible: FALSE);

    $result = $this->monitor()->run();

    $this->assertSame(1, $result['failing']);
    $this->assertSame(0, $result['transitions']);
    $this->assertSame([], $this->bufferedBodies());
    $this->assertSame('fail', $this->state()['checkOutboundMail']['status']);
    $this->assertFalse($this->state()['checkOutboundMail']['notified']);
  }

  public function testHeraldSnoozesByKeyPrefixAndWithoutEndKeepTheRunSilent(): void {
    \Civi::settings()->set(Snooze::SETTING, [
      'checkExtensionsRequired' => '2099-01-01T00:00:00+00:00',
      'sentinel_*' => '',
    ]);
    $this->message('checkExtensionsRequired:a', self::ERROR);
    $this->message('sentinel_bounces', self::ERROR);

    $result = $this->monitor()->run();

    $this->assertSame(2, $result['failing']);
    $this->assertSame(0, $result['transitions']);
    $this->assertSame(['checkExtensionsRequired', 'sentinel_bounces'], array_keys($this->state()));
    // A snooze on the key holds for every detail.
    $this->rows = [];
    $this->message('checkExtensionsRequired:a,b', self::ERROR);
    $this->message('sentinel_bounces', self::ERROR);
    $this->assertSame(0, $this->monitor()->run()['transitions']);
    $this->assertSame([], $this->bufferedBodies());
  }

  public function testAVisibleEscalationIsPublishedAtOnce(): void {
    $this->message('checkDiskSpace', self::WARNING);
    $this->monitor()->run();
    $this->rows = [];
    $this->message('checkDiskSpace', self::ERROR);

    $this->assertSame(1, $this->monitor()->run()['transitions']);
    $this->assertStringContainsString('changed', $this->bufferedBodies()[1]);
    $this->assertSame(self::ERROR, $this->state()['checkDiskSpace']['severity']);
  }

  /**
   * Core hides messages by full name, so one key can mix visible and hidden
   * messages; only the visible ones count for an escalation or change.
   */
  public function testAHiddenMessageOfAVisibleKeyCannotTriggerAChange(): void {
    $this->message('probe:a', self::WARNING);
    $this->message('probe:b', self::ERROR, visible: FALSE);
    $this->assertSame(1, $this->monitor()->run()['transitions']);

    $this->rows = [];
    $this->message('probe:a', self::WARNING);
    $this->message('probe:b', self::CRITICAL, visible: FALSE);
    $this->assertSame(0, $this->monitor()->run()['transitions']);
    $this->assertCount(1, $this->bufferedBodies());
  }

  /**
   * Two messages a and b are another finding than one message named "a, b".
   */
  public function testTheDetailsAreComparedAsAList(): void {
    $this->message('probe:a', self::WARNING);
    $this->message('probe:b', self::WARNING);
    $this->monitor()->run();

    $this->rows = [];
    $this->message('probe:a, b', self::WARNING);
    $this->assertSame(1, $this->monitor()->run()['transitions']);
    $this->assertStringContainsString('changed', $this->bufferedBodies()[1]);
    $this->assertSame(['a, b'], $this->state()['probe']['detail']);
  }

  public function testTheShippedWardenExceptionKeepsAWardenWarningSilent(): void {
    $this->message('warden_discovery_main', self::WARNING);
    $this->assertSame(0, $this->monitor()->run()['failing']);
    $this->rows = [];
    $this->message('warden_discovery_main', self::ERROR);
    $this->assertSame(1, $this->monitor()->run()['transitions']);
  }

  public function testAMissingExpectedExtensionFails(): void {
    \Civi::settings()->set(ExpectedExtensions::SETTING, ['herald', 'org.example.absent']);

    $result = $this->monitor()->run();

    $this->assertSame(1, $result['failing']);
    $this->assertSame(1, $result['transitions']);
    $this->assertSame(['herald_expected_org.example.absent'], array_keys($this->state()));
    $this->assertStringContainsString('org.example.absent', $this->bufferedBodies()[0]);
  }

  public function testThePreviewSendsNothingAndWritesNoMonitorSetting(): void {
    \Civi::settings()->set(Snooze::SETTING, ['gone' => '2000-01-01T00:00:00+00:00']);
    \Civi::settings()->set(Monitor::STATE_SETTING, [
      'recovered' => ['status' => 'fail', 'notified' => TRUE, 'last_notified' => 1, 'severity' => self::WARNING],
    ]);
    $this->message('checkOutboundMail:smtp', self::ERROR, '<p>Mail off</p>');
    $this->message('checkPhpVersion', self::INFO);
    // Hidden in core: its state would change, but nothing is announced.
    $this->message('checkCron', self::ERROR, visible: FALSE);

    // The API action runs on the real System.check.
    \Civi\Api4\Herald::monitorPreview(FALSE)->execute();
    $rows = $this->monitor()->preview();

    $this->assertSame(
      [
        [
          'check' => 'checkOutboundMail',
          'status' => 'fail',
          'title' => 'Title of checkOutboundMail:smtp',
          'detail' => 'smtp',
          'text' => 'Mail off',
          'severity' => 'error',
          'renotify' => FALSE,
        ],
        [
          'check' => 'recovered',
          'status' => 'ok',
          'title' => '',
          'detail' => '',
          'text' => '',
          'severity' => '',
          'renotify' => FALSE,
        ],
      ],
      $rows,
    );
    $this->assertSame([], $this->bufferedBodies());
    $this->assertSame(['recovered'], array_keys($this->state()));
    $this->assertSame(['gone' => '2000-01-01T00:00:00+00:00'], \Civi::settings()->get(Snooze::SETTING));
  }

  /**
   * The topic setting is admin-editable and typed as a plain setting, so it can
   * come back as something that is not a string at all. Narrowing falls back to
   * the documented default topic; PHP's cast would look for a topic literally
   * named "Array", the router would throw, and the alert would be lost.
   */
  public function testNonScalarTopicSettingFallsBackToTheDefaultTopic(): void {
    \Civi::settings()->set(Monitor::TOPIC_SETTING, ['ops', 'oops']);
    $this->message('checkDiskSpace', self::ERROR, 'disk full');

    $result = $this->monitor()->run();

    $this->assertSame(1, $result['transitions']);
    $bodies = $this->bufferedBodies();
    $this->assertCount(1, $bodies);
    $this->assertStringContainsString('disk full', $bodies[0]);
  }

  public function testLapsedSnoozeEntriesArePrunedFromTheSetting(): void {
    \Civi::settings()->set(Snooze::SETTING, [
      'lapsed' => '2000-01-01T00:00:00Z',
      'later' => '2099-01-01T00:00:00Z',
      'forever*' => '',
      'garbage' => 'not a timestamp',
    ]);

    $this->monitor()->run();

    $this->assertSame(
      ['later' => '2099-01-01T00:00:00Z', 'forever*' => ''],
      \Civi::settings()->get(Snooze::SETTING),
    );
  }

  /**
   * A transition whose publish fails keeps the old state, so the next run
   * retries it, and the scheduled job reports the run as failed.
   */
  public function testFailedPublishIsCountedRetriedAndFailsTheJob(): void {
    \Civi::settings()->set(Monitor::TOPIC_SETTING, 'unrouted');
    $this->message('checkDiskSpace', self::ERROR, 'disk full');

    $result = $this->monitor()->run();

    $this->assertSame(0, $result['transitions']);
    $this->assertSame(1, $result['publish_failures']);
    $this->assertArrayNotHasKey('checkDiskSpace', $this->state());

    \Civi::settings()->set(Monitor::TOPIC_SETTING, 'ops');
    $retry = $this->monitor()->run();
    $this->assertSame(1, $retry['transitions']);
    $this->assertSame(0, $retry['publish_failures']);
  }

  /**
   * The scheduled job runs on the real System.check; an unrouted topic fails it.
   */
  public function testTheJobFailsWhenAPublishFails(): void {
    \Civi::settings()->set(ExpectedExtensions::SETTING, ['org.example.absent']);
    \Civi::settings()->set(Monitor::TOPIC_SETTING, 'unrouted');
    try {
      civicrm_api3('Job', 'herald_monitor', []);
      $this->fail('a run with a failed publish must not report success');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertMatchesRegularExpression('/\d+ transition\(s\) could not be published/', $e->getMessage());
    }
    \Civi::settings()->set(Monitor::TOPIC_SETTING, 'ops');
    $retry = civicrm_api3('Job', 'herald_monitor', []);
    $this->assertSame(0, $retry['values'][0]['publish_failures']);
    $this->assertArrayHasKey('herald_expected_org.example.absent', $this->state());
  }

  /**
   * GET_LOCK is re-entrant within one connection, so another process holding
   * the lock is a boot lock manager whose locks are never acquired.
   */
  public function testAConcurrentRunSkips(): void {
    $boot = &\Civi::$statics[\Civi\Core\Container::class]['boot'];
    $original = $boot['lockManager'];
    $held = new class implements \Civi\Core\Lock\LockInterface {

      public function acquire($timeout = NULL) {
        return FALSE;
      }

      public function release() {
        return TRUE;
      }

      public function isFree() {
        return FALSE;
      }

      public function isAcquired() {
        return FALSE;
      }

    };
    $manager = (new \Civi\Core\Lock\LockManager())->register(
      '/^/',
      static fn (): \Civi\Core\Lock\LockInterface => $held,
    );
    $boot['lockManager'] = $manager;
    try {
      $this->assertSame(['skipped_locked' => TRUE], $this->monitor()->run());
    }
    finally {
      $boot['lockManager'] = $original;
    }
  }

}
