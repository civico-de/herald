<?php

namespace Civi\Herald\Monitor;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for Monitor::computeTransitions (the injectable test-seam).
 *
 * computeTransitions returns, per observed check, a plan
 *   ['check' => …, 'state' => <persist>, 'publish' => <vars>|NULL].
 * The Civi wrapper threads state + publishes; these tests do the same in-memory
 * so the whole state machine — hysteresis, renotify, snooze, missing — is
 * covered without a booted CiviCRM.
 *
 * @group unit
 */
class MonitorTest extends TestCase {

  /**
   * Feed one observation set, return [newState, publishes].
   *
   * @param array<string, array<string, mixed>> $state
   * @param array<string, array{status: string, detail?: string}> $obs
   * @param array<string, mixed> $config
   * @return array{0: array<string, array<string,mixed>>, 1: list<array<string,mixed>>}
   */
  private function step(array $state, array $obs, array $config): array {
    $plans = Monitor::computeTransitions($state, $obs, $config);
    $publishes = [];
    foreach ($plans as $p) {
      $state[$p['check']] = $p['state'];
      if ($p['publish'] !== NULL) {
        $publishes[] = $p['publish'];
      }
    }
    // Mirror the wrapper: drop state for checks no longer observed.
    foreach (array_keys($state) as $name) {
      if (!isset($obs[$name])) {
        unset($state[$name]);
      }
    }
    return [$state, $publishes];
  }

  private function ok(string $detail = ''): array {
    return ['status' => 'ok', 'detail' => $detail];
  }

  private function fail(string $detail = ''): array {
    return ['status' => 'fail', 'detail' => $detail];
  }

  // --- classic behaviour (defaults: open=1, close=1, renotify off) ----------

  public function testOkToFailAndBackAlert(): void {
    [$state, $pub] = $this->step([], ['a' => $this->fail('boom')], []);
    $this->assertSame(
      [['check' => 'a', 'status' => 'fail', 'detail' => 'boom', 'renotify' => FALSE]],
      $pub
    );
    // Recovery closes the loop.
    [, $pub] = $this->step($state, ['a' => $this->ok()], []);
    $this->assertSame(
      [['check' => 'a', 'status' => 'ok', 'detail' => '', 'renotify' => FALSE]],
      $pub
    );
  }

  public function testSteadyStateStaysSilent(): void {
    [$state, $pub] = $this->step([], ['a' => $this->fail()], []);
    $this->assertCount(1, $pub);
    // Second failing run: no new message.
    [, $pub] = $this->step($state, ['a' => $this->fail()], []);
    $this->assertSame([], $pub);
  }

  public function testNewCheckAlertsOnlyWhenFailing(): void {
    [, $pub] = $this->step([], ['new_ok' => $this->ok(), 'new_fail' => $this->fail()], []);
    $this->assertSame(
      [['check' => 'new_fail', 'status' => 'fail', 'detail' => '', 'renotify' => FALSE]],
      $pub
    );
  }

  // --- hysteresis -----------------------------------------------------------

  public function testOpenAfterRidesOutFlapping(): void {
    $cfg = ['open_after' => 3, 'close_after' => 1];
    $state = [];
    // Two fails: below threshold, silent.
    [$state, $pub] = $this->step($state, ['a' => $this->fail('x')], $cfg);
    $this->assertSame([], $pub);
    [$state, $pub] = $this->step($state, ['a' => $this->fail('y')], $cfg);
    $this->assertSame([], $pub);
    // Third consecutive fail: now report, with the freshest detail.
    [$state, $pub] = $this->step($state, ['a' => $this->fail('z')], $cfg);
    $this->assertSame([['check' => 'a', 'status' => 'fail', 'detail' => 'z', 'renotify' => FALSE]], $pub);
  }

  public function testOpenAfterCounterResetsOnRecovery(): void {
    $cfg = ['open_after' => 2, 'close_after' => 1];
    $state = [];
    [$state, $pub] = $this->step($state, ['a' => $this->fail()], $cfg);
    $this->assertSame([], $pub);
    // An ok in between resets the fail streak.
    [$state, $pub] = $this->step($state, ['a' => $this->ok()], $cfg);
    $this->assertSame([], $pub);
    [$state, $pub] = $this->step($state, ['a' => $this->fail()], $cfg);
    $this->assertSame([], $pub, 'single fail after reset is below threshold');
    [$state, $pub] = $this->step($state, ['a' => $this->fail()], $cfg);
    $this->assertCount(1, $pub);
  }

  public function testCloseAfterDelaysRecovery(): void {
    $cfg = ['open_after' => 1, 'close_after' => 2];
    [$state, $pub] = $this->step([], ['a' => $this->fail()], $cfg);
    $this->assertCount(1, $pub);
    // One ok: below close threshold, stay reported-fail, silent.
    [$state, $pub] = $this->step($state, ['a' => $this->ok()], $cfg);
    $this->assertSame([], $pub);
    // Second consecutive ok: recovery reported.
    [$state, $pub] = $this->step($state, ['a' => $this->ok()], $cfg);
    $this->assertSame([['check' => 'a', 'status' => 'ok', 'detail' => '', 'renotify' => FALSE]], $pub);
  }

  // --- renotify -------------------------------------------------------------

  public function testRenotifyAfterWindow(): void {
    $hour = 3600;
    $cfg = fn(int $now) => ['renotify_seconds' => 4 * $hour, 'now' => $now];
    [$state, $pub] = $this->step([], ['a' => $this->fail('down')], $cfg(0));
    $this->assertSame([['check' => 'a', 'status' => 'fail', 'detail' => 'down', 'renotify' => FALSE]], $pub);
    // 3h later: within window, silent.
    [$state, $pub] = $this->step($state, ['a' => $this->fail('down')], $cfg(3 * $hour));
    $this->assertSame([], $pub);
    // 5h: past 4h window → renotify.
    [$state, $pub] = $this->step($state, ['a' => $this->fail('still')], $cfg(5 * $hour));
    $this->assertSame([['check' => 'a', 'status' => 'fail', 'detail' => 'still', 'renotify' => TRUE]], $pub);
    // Window restarts from the last notification; 6h alone is < 4h since 5h.
    [$state, $pub] = $this->step($state, ['a' => $this->fail('still')], $cfg(6 * $hour));
    $this->assertSame([], $pub);
    // 9h+: past 4h since the 5h renotify.
    [, $pub] = $this->step($state, ['a' => $this->fail('still')], $cfg(10 * $hour));
    $this->assertSame([['check' => 'a', 'status' => 'fail', 'detail' => 'still', 'renotify' => TRUE]], $pub);
  }

  public function testRenotifyOffByDefault(): void {
    [$state, $pub] = $this->step([], ['a' => $this->fail()], ['renotify_seconds' => 0, 'now' => 0]);
    $this->assertCount(1, $pub);
    [, $pub] = $this->step($state, ['a' => $this->fail()], ['renotify_seconds' => 0, 'now' => 999999]);
    $this->assertSame([], $pub);
  }

  // --- snooze ---------------------------------------------------------------

  public function testSnoozeSuppressesFailAndRenotify(): void {
    $cfg = fn(int $now) => [
      'renotify_seconds' => 3600,
      'snooze' => ['a' => '2026-01-01T01:00:00Z'],
      'now' => $now,
    ];
    $t0 = strtotime('2026-01-01T00:00:00Z');
    // Failing while snoozed: no message, but internal state flips to fail.
    [$state, $pub] = $this->step([], ['a' => $this->fail('boom')], $cfg($t0));
    $this->assertSame([], $pub);
    $this->assertSame('fail', $state['a']['status']);
    $this->assertFalse($state['a']['notified']);
    // Still snoozed 30 min later: still silent.
    [$state, $pub] = $this->step($state, ['a' => $this->fail('boom')], $cfg($t0 + 1800));
    $this->assertSame([], $pub);
  }

  public function testSnoozeExpiryAnnouncesDelayedFail(): void {
    $t0 = strtotime('2026-01-01T00:00:00Z');
    $snoozed = ['snooze' => ['a' => '2026-01-01T01:00:00Z'], 'now' => $t0];
    [$state, $pub] = $this->step([], ['a' => $this->fail('boom')], $snoozed);
    $this->assertSame([], $pub);
    // Snooze lapsed, still failing → delayed first alert.
    $after = ['snooze' => ['a' => '2026-01-01T01:00:00Z'], 'now' => $t0 + 7200];
    [, $pub] = $this->step($state, ['a' => $this->fail('boom')], $after);
    $this->assertSame([['check' => 'a', 'status' => 'fail', 'detail' => 'boom', 'renotify' => FALSE]], $pub);
  }

  public function testSnoozeStillClosesLoopIfPreviouslyNotified(): void {
    // Notified BEFORE snooze; snooze set later; then recovers.
    [$state, $pub] = $this->step([], ['a' => $this->fail('boom')], ['now' => 0]);
    $this->assertCount(1, $pub);
    $this->assertTrue($state['a']['notified']);
    // Now snoozed and recovers: fail→ok still fires because a fail was announced.
    $cfg = ['snooze' => ['a' => '2099-01-01T00:00:00Z'], 'now' => 100];
    [, $pub] = $this->step($state, ['a' => $this->ok()], $cfg);
    $this->assertSame([['check' => 'a', 'status' => 'ok', 'detail' => '', 'renotify' => FALSE]], $pub);
  }

  public function testSnoozedFailThatRecoversWithoutNotifyStaysSilent(): void {
    $t0 = strtotime('2026-01-01T00:00:00Z');
    $cfg = ['snooze' => ['a' => '2026-01-01T01:00:00Z'], 'now' => $t0];
    [$state, $pub] = $this->step([], ['a' => $this->fail('boom')], $cfg);
    $this->assertSame([], $pub);
    // Recovers while the fail was never announced → no fail→ok message.
    $cfg2 = ['snooze' => ['a' => '2026-01-01T01:00:00Z'], 'now' => $t0 + 60];
    [, $pub] = $this->step($state, ['a' => $this->ok()], $cfg2);
    $this->assertSame([], $pub);
  }

  // --- missing / vanished ---------------------------------------------------

  public function testVanishedCheckIsDropped(): void {
    // Prev has a check; nothing observed this run → no plan, and wrapper drops it.
    [$state, $pub] = $this->step(
      ['gone' => ['status' => 'fail', 'notified' => TRUE]],
      [],
      []
    );
    $this->assertSame([], $pub);
    $this->assertArrayNotHasKey('gone', $state);
  }

  public function testExpectedMissingIsReportedByWrapperInjection(): void {
    // The wrapper injects a synthetic fail observation for expected-but-absent
    // checks; here we simulate that injected observation reaching the planner.
    [, $pub] = $this->step(
      [],
      ['ext.check' => $this->fail('check missing — extension disabled?')],
      []
    );
    $this->assertSame(
      [['check' => 'ext.check', 'status' => 'fail', 'detail' => 'check missing — extension disabled?', 'renotify' => FALSE]],
      $pub
    );
  }

  // --- migration: old state entries without the new keys --------------------

  public function testMigratesLegacyStateEntryFailToOk(): void {
    // Old format: bare {status, detail}, no counters/notified/last_notified.
    $legacy = ['a' => ['status' => 'fail', 'detail' => 'old']];
    [$state, $pub] = $this->step($legacy, ['a' => $this->ok()], []);
    // Legacy reported-fail is treated as notified-worthy: recovery fires.
    $this->assertSame([['check' => 'a', 'status' => 'ok', 'detail' => '', 'renotify' => FALSE]], $pub);
    $this->assertSame(0, $state['a']['consecutive_fails']);
    $this->assertSame(1, $state['a']['consecutive_oks']);
  }

  public function testMigratesLegacyStateEntryFailStaysSilentWhenStillFailing(): void {
    $legacy = ['a' => ['status' => 'fail', 'detail' => 'old']];
    [, $pub] = $this->step($legacy, ['a' => $this->fail('new')], []);
    // Already reported-fail in legacy state → no duplicate alert.
    $this->assertSame([], $pub);
  }

  public function testMigratesLegacyOkEntry(): void {
    $legacy = ['a' => ['status' => 'ok']];
    [, $pub] = $this->step($legacy, ['a' => $this->fail('boom')], []);
    $this->assertSame([['check' => 'a', 'status' => 'fail', 'detail' => 'boom', 'renotify' => FALSE]], $pub);
  }

}
