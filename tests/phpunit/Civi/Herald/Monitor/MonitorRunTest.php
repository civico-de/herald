<?php

namespace Civi\Herald\Monitor;

use Civi\Core\Event\GenericHookEvent;
use Civi\Herald\Buffer\AlertBuffer;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Monitor::run() against a booted CiviCRM — the thin Civi wrapper around the
 * pure planner (whose state machine is covered in MonitorTest): hook
 * collection, the untyped settings the wrapper narrows, the expected/missing
 * injection, snooze housekeeping and the publish call.
 *
 * No network is touched: the `ops` route is configured with
 * aggregate_minutes > 0, so Herald.publish parks the alert in the aggregation
 * buffer instead of handing it to a channel — and the buffer rows are what
 * these tests read back.
 *
 * herald contributes a check of its own (`herald.buffer_backlog`), so the
 * assertions name checks explicitly instead of counting them.
 *
 * @group headless
 */
class MonitorRunTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var callable|null
   */
  private $listener = NULL;

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->set(Monitor::STATE_SETTING, []);
    \Civi::settings()->set(Monitor::TOPIC_SETTING, 'ops');
    \Civi::settings()->set('herald_monitor_expected', []);
    \Civi::settings()->set('herald_monitor_snooze', []);
    // Deliberately no "default" entry: an alert that resolves to any topic
    // other than "ops" stays unrouted, and the wrapper swallows the failure —
    // which is exactly what makes the topic assertions below meaningful.
    \Civi::settings()->set('herald_topics', [
      'ops' => ['channel' => 'telegram', 'chat_id' => '-1001234', 'aggregate_minutes' => 30],
    ]);
  }

  protected function tearDown(): void {
    if ($this->listener !== NULL) {
      \Civi::dispatcher()->removeListener('hook_civicrm_heraldChecks', $this->listener);
      $this->listener = NULL;
    }
    parent::tearDown();
  }

  /**
   * Contribute checks the way another extension would, via the hook.
   *
   * @param array<array-key, mixed> $checks
   */
  private function registerChecks(array $checks): void {
    $this->listener = static function (GenericHookEvent $e) use ($checks): void {
      foreach ($checks as $name => $callable) {
        $e->checks[$name] = $callable;
      }
    };
    \Civi::dispatcher()->addListener('hook_civicrm_heraldChecks', $this->listener);
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

  public function testHealthyChecksAreRecordedButNeverAnnounced(): void {
    $this->registerChecks([
      'herald.test.ok' => static fn(): array => ['status' => 'ok'],
      // 'skipped' means "check disabled by config", not "outage".
      'herald.test.skipped' => static fn(): array => ['status' => 'skipped', 'detail' => 'off'],
    ]);

    $result = (new Monitor())->run();

    $this->assertSame(0, $result['failing']);
    $this->assertSame(0, $result['transitions']);
    $this->assertSame([], $this->bufferedBodies(), 'a steady ok state must publish nothing');
    $state = $this->state();
    $this->assertSame('ok', $state['herald.test.ok']['status']);
    $this->assertSame('ok', $state['herald.test.skipped']['status']);
  }

  /**
   * Contributors are asked for name => callable, but the hook signature cannot
   * enforce it. Malformed entries are dropped instead of fatalling the run —
   * one sloppy extension must not take the whole monitor down.
   */
  public function testMalformedHookEntriesAreIgnored(): void {
    $this->listener = static function (GenericHookEvent $e): void {
      $e->checks[] = static fn(): array => ['status' => 'fail', 'detail' => 'numeric key'];
      $e->checks['herald.test.notcallable'] = 'this is a string, not a callable';
      $e->checks['herald.test.good'] = static fn(): array => ['status' => 'ok'];
    };
    \Civi::dispatcher()->addListener('hook_civicrm_heraldChecks', $this->listener);

    $result = (new Monitor())->run();

    $this->assertSame(0, $result['failing']);
    $this->assertArrayHasKey('herald.test.good', $this->state());
    $this->assertArrayNotHasKey('herald.test.notcallable', $this->state());
  }

  public function testThrowingCheckCountsAsFailureWithItsMessageAsDetail(): void {
    $this->registerChecks([
      'herald.test.explodes' => static function (): array {
        throw new \RuntimeException('queue backend unreachable');
      },
    ]);

    $result = (new Monitor())->run();

    $this->assertSame(1, $result['failing']);
    $this->assertSame(1, $result['transitions']);
    $this->assertSame('queue backend unreachable', $this->state()['herald.test.explodes']['detail']);
    $this->assertStringContainsString('queue backend unreachable', implode("\n", $this->bufferedBodies()));
  }

  /**
   * The topic setting is admin-editable and typed as a plain setting, so it can
   * come back as something that is not a string at all. Narrowing falls back to
   * the documented default topic; PHP's cast would look for a topic literally
   * named "Array", the router would throw, and the alert would be lost.
   */
  public function testNonScalarTopicSettingFallsBackToTheDefaultTopic(): void {
    \Civi::settings()->set(Monitor::TOPIC_SETTING, ['ops', 'oops']);
    $this->registerChecks([
      'herald.test.disk' => static fn(): array => ['status' => 'fail', 'detail' => 'disk full'],
    ]);

    $result = (new Monitor())->run();

    // Routed, published, buffered — none of which happens for an unknown topic.
    $this->assertSame(1, $result['transitions']);
    $bodies = $this->bufferedBodies();
    $this->assertCount(1, $bodies);
    $this->assertStringContainsString('herald.test.disk', $bodies[0]);
    $this->assertStringContainsString('disk full', $bodies[0]);
    $this->assertSame('fail', $this->state()['herald.test.disk']['status']);
  }

  /**
   * A check named in herald_monitor_expected but absent from the hook is a
   * failure (its extension was probably disabled), not a silent drop.
   */
  public function testExpectedButAbsentCheckIsReportedAsFailure(): void {
    \Civi::settings()->set('herald_monitor_expected', ['someext.queue']);

    $result = (new Monitor())->run();

    $this->assertSame(1, $result['failing']);
    $this->assertSame(1, $result['transitions']);
    $this->assertSame('fail', $this->state()['someext.queue']['status']);
    $this->assertCount(1, $this->bufferedBodies());
  }

  /**
   * A published transition is persisted immediately, so the next run is silent
   * — the monitor reports edges, not states.
   */
  public function testSecondRunOfTheSameFailureStaysSilent(): void {
    $this->registerChecks([
      'herald.test.stuck' => static fn(): array => ['status' => 'fail', 'detail' => 'still down'],
    ]);

    $this->assertSame(1, (new Monitor())->run()['transitions']);
    $this->assertSame(0, (new Monitor())->run()['transitions']);
    $this->assertCount(1, $this->bufferedBodies());
  }

  /**
   * Housekeeping: a snooze whose deadline has passed suppresses nothing, so it
   * is dropped from the setting rather than accumulating forever.
   */
  public function testLapsedSnoozeEntriesArePrunedFromTheSetting(): void {
    \Civi::settings()->set('herald_monitor_snooze', [
      'herald.test.snoozed' => '2000-01-01T00:00:00Z',
      'herald.test.later' => '2099-01-01T00:00:00Z',
      'herald.test.garbage' => 'not a timestamp',
    ]);
    $this->registerChecks([
      'herald.test.snoozed' => static fn(): array => ['status' => 'ok'],
    ]);

    (new Monitor())->run();

    $this->assertSame(
      ['herald.test.later' => '2099-01-01T00:00:00Z'],
      \Civi::settings()->get('herald_monitor_snooze')
    );
  }

  /**
   * A snoozed failure flips the internal state but must not be announced.
   */
  public function testSnoozedFailureIsRecordedWithoutPublishing(): void {
    \Civi::settings()->set('herald_monitor_snooze', ['herald.test.noisy' => '2099-01-01T00:00:00Z']);
    $this->registerChecks([
      'herald.test.noisy' => static fn(): array => ['status' => 'fail', 'detail' => 'flapping'],
    ]);

    $result = (new Monitor())->run();

    $this->assertSame(1, $result['failing']);
    $this->assertSame(0, $result['transitions']);
    $this->assertSame([], $this->bufferedBodies());
    $this->assertSame('fail', $this->state()['herald.test.noisy']['status']);
    $this->assertFalse($this->state()['herald.test.noisy']['notified']);
  }

  /**
   * An uninstalled extension is not an outage: its stale state entry is dropped
   * without a recovery alert.
   */
  public function testVanishedCheckLosesItsStateEntry(): void {
    \Civi::settings()->set(Monitor::STATE_SETTING, [
      'gone.check' => ['status' => 'fail', 'detail' => 'from a previous life', 'notified' => TRUE],
    ]);

    $result = (new Monitor())->run();

    $this->assertArrayNotHasKey('gone.check', $this->state());
    $this->assertSame(0, $result['transitions']);
    $this->assertSame([], $this->bufferedBodies());
  }

}
