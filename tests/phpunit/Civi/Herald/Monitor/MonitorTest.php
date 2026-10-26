<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for Monitor::computeTransitions (the injectable test-seam).
 *
 * computeTransitions returns a plan per key that is failing or has state:
 *   ['check' => …, 'state' => <persist>|NULL (forget), 'publish' => <vars>|NULL].
 * The Civi wrapper threads state + publishes; these tests do the same in-memory
 * so the whole state machine — hysteresis, renotify, muting, changes — is
 * covered without a booted CiviCRM.
 *
 * @group unit
 */
class MonitorTest extends TestCase {

  private const WARNING = 3;

  private const ERROR = 4;

  /**
   * Feed one observation set, return [newState, publishes].
   *
   * @param array<string, array<string, mixed>> $state
   * @param array<string, array<string, mixed>> $obs
   * @param array<string, mixed> $config
   * @return array{0: array<string, array<string,mixed>>, 1: list<array<string,mixed>>}
   */
  private function step(array $state, array $obs, array $config): array {
    $plans = Monitor::computeTransitions($state, $obs, $config);
    $publishes = [];
    foreach ($plans as $p) {
      unset($state[$p['check']]);
      if ($p['state'] !== NULL) {
        $state[$p['check']] = $p['state'];
      }
      if ($p['publish'] !== NULL) {
        $publishes[] = $p['publish'];
      }
    }
    return [$state, $publishes];
  }

  private function obsOk(): array {
    return ['status' => 'ok'];
  }

  /**
   * @param string $detail The key's details, joined by ', '.
   */
  private function obsFail(
    string $detail = '',
    int $severity = self::WARNING,
    bool $visible = TRUE,
    string $text = '',
  ): array {
    return [
      'status' => 'fail',
      'severity' => $severity,
      'detail' => $detail === '' ? [] : explode(', ', $detail),
      'title' => 'Title',
      'text' => $text,
      'visible' => $visible,
    ];
  }

  /**
   * The publish vars the planner hands to the template.
   *
   * @param array{severity?: int, title?: string} $extra
   * @return array<string, mixed>
   */
  private function msg(string $check, string $status, string $detail, bool $renotify, array $extra = []): array {
    $ok = $status === 'ok';
    return [
      'check' => $check,
      'status' => $status,
      'title' => $extra['title'] ?? 'Title',
      'detail' => $ok ? '' : $detail,
      'text' => '',
      'severity' => $extra['severity'] ?? ($ok ? 0 : self::WARNING),
      'renotify' => $renotify,
    ];
  }

  // --- classic behaviour (defaults: open=1, close=1, renotify off) ----------

  public function testOkToFailAndBackAlert(): void {
    [$state, $pub] = $this->step([], ['a' => $this->obsFail('boom')], []);
    $this->assertSame(
      [$this->msg('a', 'fail', 'boom', FALSE)],
      $pub,
    );
    // Recovery closes the loop.
    [, $pub] = $this->step($state, ['a' => $this->obsOk()], []);
    $this->assertSame(
      [$this->msg('a', 'ok', '', FALSE)],
      $pub,
    );
  }

  public function testSteadyStateStaysSilent(): void {
    [$state, $pub] = $this->step([], ['a' => $this->obsFail()], []);
    $this->assertCount(1, $pub);
    // Second failing run: no new message.
    [, $pub] = $this->step($state, ['a' => $this->obsFail()], []);
    $this->assertSame([], $pub);
  }

  public function testNewCheckAlertsOnlyWhenFailing(): void {
    [, $pub] = $this->step([], ['new_ok' => $this->obsOk(), 'new_fail' => $this->obsFail()], []);
    $this->assertSame(
      [$this->msg('new_fail', 'fail', '', FALSE)],
      $pub,
    );
  }

  // --- hysteresis -----------------------------------------------------------

  public function testOpenAfterRidesOutFlapping(): void {
    $cfg = ['open_after' => 3, 'close_after' => 1];
    $state = [];
    // Two fails: below threshold, silent.
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('x')], $cfg);
    $this->assertSame([], $pub);
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('y')], $cfg);
    $this->assertSame([], $pub);
    // Third consecutive fail: now report, with the freshest detail.
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('z')], $cfg);
    $this->assertSame([$this->msg('a', 'fail', 'z', FALSE)], $pub);
  }

  public function testOpenAfterCounterResetsOnRecovery(): void {
    $cfg = ['open_after' => 2, 'close_after' => 1];
    $state = [];
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail()], $cfg);
    $this->assertSame([], $pub);
    // An ok in between resets the fail streak.
    [$state, $pub] = $this->step($state, ['a' => $this->obsOk()], $cfg);
    $this->assertSame([], $pub);
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail()], $cfg);
    $this->assertSame([], $pub, 'single fail after reset is below threshold');
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail()], $cfg);
    $this->assertCount(1, $pub);
  }

  public function testCloseAfterDelaysRecovery(): void {
    $cfg = ['open_after' => 1, 'close_after' => 2];
    [$state, $pub] = $this->step([], ['a' => $this->obsFail()], $cfg);
    $this->assertCount(1, $pub);
    // One ok: below close threshold, stay reported-fail, silent.
    [$state, $pub] = $this->step($state, ['a' => $this->obsOk()], $cfg);
    $this->assertSame([], $pub);
    // Second consecutive ok: recovery reported.
    [$state, $pub] = $this->step($state, ['a' => $this->obsOk()], $cfg);
    $this->assertSame([$this->msg('a', 'ok', '', FALSE)], $pub);
  }

  // --- renotify -------------------------------------------------------------

  public function testRenotifyAfterWindow(): void {
    $hour = 3600;
    $cfg = static fn (int $now) => ['renotify_seconds' => 4 * $hour, 'now' => $now];
    [$state, $pub] = $this->step([], ['a' => $this->obsFail('down')], $cfg(0));
    $this->assertSame([$this->msg('a', 'fail', 'down', FALSE)], $pub);
    // 3h later: within window, silent.
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('down')], $cfg(3 * $hour));
    $this->assertSame([], $pub);
    // 5h: past 4h window → renotify.
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('down')], $cfg(5 * $hour));
    $this->assertSame([$this->msg('a', 'fail', 'down', TRUE)], $pub);
    // Window restarts from the last notification; 6h alone is < 4h since 5h.
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('down')], $cfg(6 * $hour));
    $this->assertSame([], $pub);
    // 9h+: past 4h since the 5h renotify.
    [, $pub] = $this->step($state, ['a' => $this->obsFail('down')], $cfg(10 * $hour));
    $this->assertSame([$this->msg('a', 'fail', 'down', TRUE)], $pub);
  }

  public function testRenotifyOffByDefault(): void {
    [$state, $pub] = $this->step([], ['a' => $this->obsFail()], ['renotify_seconds' => 0, 'now' => 0]);
    $this->assertCount(1, $pub);
    [, $pub] = $this->step($state, ['a' => $this->obsFail()], ['renotify_seconds' => 0, 'now' => 999_999]);
    $this->assertSame([], $pub);
  }

  // --- snooze ---------------------------------------------------------------

  public function testSnoozeSuppressesFailAndRenotify(): void {
    $cfg = static fn (int $now) => [
      'renotify_seconds' => 3600,
      'snooze' => ['a' => '2026-01-01T01:00:00Z'],
      'now' => $now,
    ];
    $t0 = strtotime('2026-01-01T00:00:00Z');
    // Failing while snoozed: no message, but internal state flips to fail.
    [$state, $pub] = $this->step([], ['a' => $this->obsFail('boom')], $cfg($t0));
    $this->assertSame([], $pub);
    $this->assertSame('fail', $state['a']['status']);
    $this->assertFalse($state['a']['notified']);
    // Still snoozed 30 min later: still silent.
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('boom')], $cfg($t0 + 1800));
    $this->assertSame([], $pub);
  }

  public function testSnoozeExpiryAnnouncesDelayedFail(): void {
    $t0 = strtotime('2026-01-01T00:00:00Z');
    $snoozed = ['snooze' => ['a' => '2026-01-01T01:00:00Z'], 'now' => $t0];
    [$state, $pub] = $this->step([], ['a' => $this->obsFail('boom')], $snoozed);
    $this->assertSame([], $pub);
    // Snooze lapsed, still failing → delayed first alert.
    $after = ['snooze' => ['a' => '2026-01-01T01:00:00Z'], 'now' => $t0 + 7200];
    [, $pub] = $this->step($state, ['a' => $this->obsFail('boom')], $after);
    $this->assertSame([$this->msg('a', 'fail', 'boom', FALSE)], $pub);
  }

  public function testSnoozeStillClosesLoopIfPreviouslyNotified(): void {
    // Notified BEFORE snooze; snooze set later; then recovers.
    [$state, $pub] = $this->step([], ['a' => $this->obsFail('boom')], ['now' => 0]);
    $this->assertCount(1, $pub);
    $this->assertTrue($state['a']['notified']);
    // Now snoozed and recovers: fail→ok still fires because a fail was announced.
    $cfg = ['snooze' => ['a' => '2099-01-01T00:00:00Z'], 'now' => 100];
    [, $pub] = $this->step($state, ['a' => $this->obsOk()], $cfg);
    $this->assertSame([$this->msg('a', 'ok', '', FALSE)], $pub);
  }

  public function testSnoozedFailThatRecoversWithoutNotifyStaysSilent(): void {
    $t0 = strtotime('2026-01-01T00:00:00Z');
    $cfg = ['snooze' => ['a' => '2026-01-01T01:00:00Z'], 'now' => $t0];
    [$state, $pub] = $this->step([], ['a' => $this->obsFail('boom')], $cfg);
    $this->assertSame([], $pub);
    // Recovers while the fail was never announced → no fail→ok message.
    $cfg2 = ['snooze' => ['a' => '2026-01-01T01:00:00Z'], 'now' => $t0 + 60];
    [, $pub] = $this->step($state, ['a' => $this->obsOk()], $cfg2);
    $this->assertSame([], $pub);
  }

  // --- missing / vanished ---------------------------------------------------

  /**
   * Checks that are healthy say nothing, so a key in the state without a
   * message this run has recovered; the entry is then forgotten.
   */
  public function testKeyWithoutMessageRecoversAndIsForgotten(): void {
    [$state] = $this->step([], ['gone' => $this->obsFail('x')], []);
    [$state, $pub] = $this->step($state, [], []);
    $this->assertSame([$this->msg('gone', 'ok', '', FALSE)], $pub);
    $this->assertArrayNotHasKey('gone', $state);
  }

  public function testHealthyKeysWithoutStateGetNoPlanAndNoState(): void {
    $plans = Monitor::computeTransitions([], ['info' => ['status' => 'ok', 'severity' => 1]], []);
    $this->assertSame([], $plans);
  }

  public function testUnreportedFailureThatRecoversIsForgottenSilently(): void {
    [$state, $pub] = $this->step([], ['a' => $this->obsFail()], ['open_after' => 2]);
    $this->assertSame([], $pub);
    $this->assertSame(1, $state['a']['consecutive_fails']);
    [$state, $pub] = $this->step($state, ['a' => $this->obsOk()], ['open_after' => 2]);
    $this->assertSame([], $pub);
    $this->assertSame([], $state);
  }

  // --- migration: old state entries without the new keys --------------------

  public function testMigratesLegacyStateEntryFailToOk(): void {
    // Bare {status, detail} entry without counters/notified/last_notified.
    $legacy = ['a' => ['status' => 'fail', 'detail' => 'old']];
    [$state, $pub] = $this->step($legacy, ['a' => $this->obsOk()], []);
    // A bare reported-fail counts as notified: recovery fires.
    $this->assertSame([$this->msg('a', 'ok', '', FALSE, ['title' => ''])], $pub);
    $this->assertArrayNotHasKey('a', $state);
  }

  public function testMigratesLegacyStateEntryFailStaysSilentWhenStillFailing(): void {
    $legacy = ['a' => ['status' => 'fail', 'detail' => 'old', 'severity' => self::WARNING]];
    [, $pub] = $this->step($legacy, ['a' => $this->obsFail('old')], []);
    // Already reported-fail → no duplicate alert.
    $this->assertSame([], $pub);
  }

  public function testMigratesLegacyOkEntry(): void {
    $legacy = ['a' => ['status' => 'ok']];
    [, $pub] = $this->step($legacy, ['a' => $this->obsFail('boom')], []);
    $this->assertSame([$this->msg('a', 'fail', 'boom', FALSE)], $pub);
  }

  // --- regression: no fail alerts while the current observation is ok -------

  public function testLapsedSnoozeDoesNotAnnounceDuringRecoveryHysteresis(): void {
    // Snoozed fail flipped internal state silently; snooze lapsed while the
    // check is ALREADY observing ok, but close_after=2 keeps the episode open.
    // The lapsed snooze must NOT fire a delayed fail alert off an ok run.
    $state = [
      'a' => [
        'status' => 'fail',
        'detail' => 'old',
        'consecutive_fails' => 3,
        'consecutive_oks' => 0,
        'notified' => FALSE,
        'last_notified' => NULL,
      ],
    ];
    [$state, $pub] = $this->step($state, ['a' => $this->obsOk()], ['close_after' => 2, 'now' => 1000]);
    $this->assertSame([], $pub);
    // Second ok closes the episode — silently, because the fail was never announced.
    [, $pub] = $this->step($state, ['a' => $this->obsOk()], ['close_after' => 2, 'now' => 2000]);
    $this->assertSame([], $pub);
  }

  public function testNoRenotifyDuringRecoveryHysteresis(): void {
    $state = [
      'a' => [
        'status' => 'fail',
        'detail' => 'boom',
        'consecutive_fails' => 5,
        'consecutive_oks' => 0,
        'notified' => TRUE,
        'last_notified' => 0,
      ],
    ];
    // Renotify window long past, but the current observation is ok (close_after=2):
    // re-announcing a possibly-over incident would be wrong.
    [, $pub] = $this->step(
      $state,
      ['a' => $this->obsOk()],
      [
        'close_after' => 2,
        'renotify_seconds' => 60,
        'now' => 100_000,
      ],
    );
    $this->assertSame([], $pub);
  }

  /**
   * The state setting is JSON in the DB and hand-editable in the UI, so a
   * counter can come back as something that is not a number at all. The
   * narrowing in Monitor::int() restarts it from the documented default 0
   * instead of taking PHP's cast of an array — which is 1 for any non-empty
   * array and would silently credit a fail run-length that never happened,
   * tripping open_after a full run too early.
   */
  public function testNonScalarCountersRestartFromZeroInsteadOfPhpsArrayCast(): void {
    $corrupt = [
      'a' => [
        'status' => 'ok',
        'detail' => '',
        'consecutive_fails' => ['not', 'a', 'number'],
        'consecutive_oks' => ['neither'],
        'notified' => FALSE,
        'last_notified' => NULL,
      ],
    ];
    $cfg = ['open_after' => 2];

    [$state, $pub] = $this->step($corrupt, ['a' => $this->obsFail('boom')], $cfg);
    $this->assertSame(1, $state['a']['consecutive_fails'], 'garbage counter restarts at 0, this is fail #1');
    $this->assertSame([], $pub, 'one fail is below open_after=2');

    // Only the second real observation crosses the threshold.
    [, $pub] = $this->step($state, ['a' => $this->obsFail('boom')], $cfg);
    $this->assertCount(1, $pub);
  }

  /**
   * Same helper, the other counter: a garbage ok-run must not declare an
   * incident over one observation early. (int) ['garbage'] would be 1, and
   * the first ok would then satisfy close_after=2 all by itself.
   */
  public function testNonScalarOkCounterDoesNotCloseAnEpisodeEarly(): void {
    $state = [
      'a' => [
        'status' => 'fail',
        'detail' => 'boom',
        'consecutive_fails' => 3,
        'consecutive_oks' => ['garbage'],
        'notified' => TRUE,
        'last_notified' => 0,
      ],
    ];
    $cfg = ['close_after' => 2, 'now' => 1000];

    [$state, $pub] = $this->step($state, ['a' => $this->obsOk()], $cfg);
    $this->assertSame([], $pub, 'first real ok is not yet a recovery');
    $this->assertSame(1, $state['a']['consecutive_oks']);

    [, $pub] = $this->step($state, ['a' => $this->obsOk()], $cfg);
    $this->assertSame([$this->msg('a', 'ok', '', FALSE, ['title' => ''])], $pub);
  }

  public function testLegacyFailGetsRenotifyAnchor(): void {
    // Entry without notified/last_notified: inferred notified=true has no
    // last_notified, so renotify anchors to now and fires one window later.
    $legacy = ['a' => ['status' => 'fail', 'detail' => 'old', 'severity' => self::WARNING]];
    [$state, $pub] = $this->step(
      $legacy,
      ['a' => $this->obsFail('old')],
      [
        'renotify_seconds' => 3600,
        'now' => 10_000,
      ],
    );
    $this->assertSame([], $pub);
    $this->assertSame(10_000, $state['a']['last_notified']);
    [, $pub] = $this->step(
      $state,
      ['a' => $this->obsFail('old')],
      [
        'renotify_seconds' => 3600,
        'now' => 10_000 + 3600,
      ],
    );
    $this->assertCount(1, $pub);
    $this->assertTrue($pub[0]['renotify']);
  }

  // --- muting, escalation and detail changes --------------------------------

  public function testFailureHiddenByCoreChangesStateWithoutAlert(): void {
    [$state, $pub] = $this->step([], ['a' => $this->obsFail(visible: FALSE)], []);
    $this->assertSame([], $pub);
    $this->assertSame('fail', $state['a']['status']);
    $this->assertFalse($state['a']['notified']);
    // Core shows it again (its hush lapsed): the failure is reported then.
    [, $pub] = $this->step($state, ['a' => $this->obsFail()], []);
    $this->assertSame([$this->msg('a', 'fail', '', FALSE)], $pub);
  }

  public function testVisibleEscalationOfAReportedFailureIsReportedAtOnce(): void {
    [$state] = $this->step([], ['a' => $this->obsFail()], ['renotify_seconds' => 86_400, 'now' => 0]);
    [$state, $pub] = $this->step(
      $state,
      ['a' => $this->obsFail(severity: self::ERROR)],
      ['renotify_seconds' => 86_400, 'now' => 60],
    );
    $this->assertSame([$this->msg('a', 'changed', '', FALSE, ['severity' => self::ERROR])], $pub);
    $this->assertSame(self::ERROR, $state['a']['severity']);
    // Steady at error: silent again.
    [, $pub] = $this->step(
      $state,
      ['a' => $this->obsFail(severity: self::ERROR)],
      ['renotify_seconds' => 86_400, 'now' => 120],
    );
    $this->assertSame([], $pub);
  }

  public function testMutedEscalationStaysSilentUntilTheMutingEnds(): void {
    [$state] = $this->step([], ['a' => $this->obsFail()], []);
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail(severity: self::ERROR, visible: FALSE)], []);
    $this->assertSame([], $pub);
    $snoozed = ['snooze' => ['a' => ''], 'now' => 0];
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail(severity: self::ERROR)], $snoozed);
    $this->assertSame([], $pub);
    // Compared with the last report (warning), so the escalation goes out once unmuted.
    [, $pub] = $this->step($state, ['a' => $this->obsFail(severity: self::ERROR)], []);
    $this->assertSame([$this->msg('a', 'changed', '', FALSE, ['severity' => self::ERROR])], $pub);
  }

  public function testFallingSeverityIsNoChange(): void {
    [$state] = $this->step([], ['a' => $this->obsFail(severity: self::ERROR)], []);
    [, $pub] = $this->step($state, ['a' => $this->obsFail()], []);
    $this->assertSame([], $pub);
  }

  public function testDetailChangeSendsExactlyOneChangedAndNoRecovery(): void {
    [$state, $pub] = $this->step([], ['checkExtensionsRequired' => $this->obsFail('a')], []);
    $this->assertSame([$this->msg('checkExtensionsRequired', 'fail', 'a', FALSE)], $pub);
    [$state, $pub] = $this->step($state, ['checkExtensionsRequired' => $this->obsFail('a, b')], []);
    $this->assertSame([$this->msg('checkExtensionsRequired', 'changed', 'a, b', FALSE)], $pub);
    [, $pub] = $this->step($state, ['checkExtensionsRequired' => $this->obsFail('a, b')], []);
    $this->assertSame([], $pub);
  }

  public function testAStringDetailInTheStateReadsAsOneDetail(): void {
    $prev = [
      'checkExtensionsRequired' => [
        'status' => 'fail',
        'severity' => self::WARNING,
        'detail' => 'a',
        'notified' => TRUE,
        'last_notified' => 0,
      ],
    ];
    [$state, $pub] = $this->step($prev, ['checkExtensionsRequired' => $this->obsFail('a')], []);
    $this->assertSame([], $pub);
    $this->assertSame(['a'], $state['checkExtensionsRequired']['detail']);
  }

  public function testMutedDetailChangeSendsNothing(): void {
    [$state] = $this->step([], ['checkExtensionsRequired' => $this->obsFail('a')], []);
    [, $pub] = $this->step($state, ['checkExtensionsRequired' => $this->obsFail('a, b', visible: FALSE)], []);
    $this->assertSame([], $pub);
  }

  /**
   * Message texts carry timestamps and counts; only key, severity and detail are compared.
   */
  public function testChangingMessageTextOfAReportedFailureSendsNothing(): void {
    [$state] = $this->step([], ['checkLastCron' => $this->obsFail(text: 'Last run 10:00')], []);
    foreach (['Last run 10:05', 'Last run 10:10', 'Last run 10:15'] as $text) {
      [$state, $pub] = $this->step($state, ['checkLastCron' => $this->obsFail(text: $text)], []);
      $this->assertSame([], $pub);
    }
  }

  public function testMutedFailureGetsNoReminder(): void {
    [$state] = $this->step([], ['a' => $this->obsFail()], ['renotify_seconds' => 60, 'now' => 0]);
    [, $pub] = $this->step($state, ['a' => $this->obsFail(visible: FALSE)], ['renotify_seconds' => 60, 'now' => 1000]);
    $this->assertSame([], $pub);
  }

  public function testRecoveryAfterAReportedFailureIsSentWhileHiddenByCore(): void {
    [$state] = $this->step([], ['a' => $this->obsFail()], []);
    [$state] = $this->step($state, ['a' => $this->obsFail(visible: FALSE)], []);
    [, $pub] = $this->step($state, [], []);
    $this->assertSame([$this->msg('a', 'ok', '', FALSE)], $pub);
  }

  public function testSnoozeByPrefixAndWithoutEnd(): void {
    $cfg = ['snooze' => ['warden_*' => '', 'checkExtensionsRequired' => '2099-01-01T00:00:00Z'], 'now' => 0];
    [$state, $pub] = $this->step(
      [],
      [
        'warden_discovery_1' => $this->obsFail(),
        'checkExtensionsRequired' => $this->obsFail('a'),
        'other' => $this->obsFail(),
      ],
      $cfg,
    );
    $this->assertSame([$this->msg('other', 'fail', '', FALSE)], $pub);
    $this->assertSame('fail', $state['warden_discovery_1']['status']);
    // A snooze on the key holds for every detail.
    [, $pub] = $this->step(
      $state,
      [
        'checkExtensionsRequired' => $this->obsFail('b'),
        'warden_discovery_1' => $this->obsFail(),
        'other' => $this->obsFail(),
      ],
      $cfg,
    );
    $this->assertSame([], $pub);
  }

  public function testAReportedFailureIsAnnouncedAgainWhenItsSnoozeEnds(): void {
    $snoozed = ['snooze' => ['a' => ''], 'now' => 1000];
    [$state, $pub] = $this->step([], ['a' => $this->obsFail('x')], ['now' => 1000]);
    $this->assertCount(1, $pub);
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('x')], $snoozed);
    $this->assertSame([], $pub, 'snoozed: silent');
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('x')], $snoozed);
    $this->assertSame([], $pub);

    // Snooze removed, reminders off, severity and detail unchanged.
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('x')], ['now' => 2000]);
    $this->assertSame([$this->msg('a', 'fail', 'x', FALSE)], $pub);
    [, $pub] = $this->step($state, ['a' => $this->obsFail('x')], ['now' => 3000]);
    $this->assertSame([], $pub, 'announced once');
  }

  public function testAReportedFailureIsAnnouncedAgainWhenCoreShowsItAgain(): void {
    [$state] = $this->step([], ['a' => $this->obsFail('x')], []);
    [$state, $pub] = $this->step($state, ['a' => $this->obsFail('x', visible: FALSE)], []);
    $this->assertSame([], $pub);
    [, $pub] = $this->step($state, ['a' => $this->obsFail('x')], []);
    $this->assertSame([$this->msg('a', 'fail', 'x', FALSE)], $pub);
  }

  public function testARecoveryWhileMutedAfterAReportedFailureIsStillSent(): void {
    [$state] = $this->step([], ['a' => $this->obsFail('x')], []);
    [$state] = $this->step($state, ['a' => $this->obsFail('x', visible: FALSE)], []);
    [, $pub] = $this->step($state, ['a' => $this->obsOk()], ['snooze' => ['a' => '']]);
    $this->assertSame([$this->msg('a', 'ok', '', FALSE)], $pub);
  }

  public function testLiveSnoozeKeepsEntriesWithoutEndAndDropsLapsedAndMalformedOnes(): void {
    $this->assertSame(
      ['a*' => '', 'b' => '2099-01-01T00:00:00Z'],
      Snooze::live(
        [
          'a*' => '',
          'b' => '2099-01-01T00:00:00Z',
          'c' => '2000-01-01T00:00:00Z',
          'd' => 'soon',
          'e:detail' => '',
          'f' => 5,
          3 => '',
        ],
        (int) strtotime('2026-01-01T00:00:00Z'),
      ),
    );
  }

}
