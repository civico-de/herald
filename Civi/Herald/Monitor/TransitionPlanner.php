<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * Pure transition planner — the test-seam of the monitor. No Civi calls:
 * everything it needs (now, thresholds, snooze) is injected via $config.
 *
 * Per check it folds the raw observation into the persisted state machine
 * (see CheckState) and decides whether a message is due. It returns, for every
 * observed check, a plan the thin Civi wrapper can act on one at a time:
 *
 *   ['check' => name, 'state' => <entry to persist>, 'publish' => <vars>|NULL]
 *
 * A check seen for the FIRST time baselines to reported-ok, so a healthy
 * newcomer is not news but a failing one still alerts once open_after is met.
 */
class TransitionPlanner {

  private MonitorConfig $config;

  /**
   * @param array{open_after?: int, close_after?: int, renotify_seconds?: int, snooze?: array<string,string>, now?: int} $config
   */
  public function __construct(array $config = []) {
    $this->config = new MonitorConfig($config);
  }

  /**
   * Plan every observed check.
   *
   * @param array<string, array<string, mixed>> $prev
   * @param array<string, array{status: string, detail?: string}> $observations
   * @return list<array{check: string, state: array<string, mixed>, publish: ?array<string, mixed>}>
   */
  public function plan(array $prev, array $observations): array {
    $plans = [];
    foreach ($observations as $name => $obs) {
      $plans[] = $this->planCheck((string) $name, $obs, $prev[$name] ?? []);
    }

    return $plans;
  }

  /**
   * Fold one observation into one check's state machine.
   *
   * @param string $name The check name.
   * @param array{status: string, detail?: string} $obs The raw observation.
   * @param array<string, mixed> $prevEntry The persisted state entry, if any.
   * @return array{check: string, state: array<string, mixed>, publish: ?array<string, mixed>}
   */
  private function planCheck(string $name, array $obs, array $prevEntry): array {
    $observation = Observation::fromArray($name, $obs);
    $state = CheckState::fromArray($prevEntry, $this->config->now);

    // Counters advance before the decision; the reported status does not, so
    // decide() can still read the episode we were in.
    $observation->failed ? $state->countFail() : $state->countOk();
    $publish = $this->decide($state, $observation);

    return ['check' => $name, 'state' => $state->toArray(), 'publish' => $publish];
  }

  /**
   * Pick the edge this observation lands on.
   *
   * @param \Civi\Herald\Monitor\CheckState $state The working state.
   * @param \Civi\Herald\Monitor\Observation $obs The normalized observation.
   * @return array<string, mixed>|null The message to publish, if any.
   */
  private function decide(CheckState $state, Observation $obs): ?array {
    if ($state->status === 'ok') {
      return $this->maybeOpen($state, $obs);
    }
    if ($state->consecutiveOks >= $this->config->closeAfter) {
      return $this->close($state, $obs);
    }

    return $this->whileFailing($state, $obs);
  }

  /**
   * Reported-ok: open a fail episode once hysteresis is met.
   *
   * Unless snoozed — then the internal state still flips but we keep quiet, so
   * a later recovery cannot fire a fail→ok for an unannounced fail.
   *
   * @param \Civi\Herald\Monitor\CheckState $state The working state.
   * @param \Civi\Herald\Monitor\Observation $obs The normalized observation.
   * @return array<string, mixed>|null The message to publish, if any.
   */
  private function maybeOpen(CheckState $state, Observation $obs): ?array {
    if ($state->consecutiveFails < $this->config->openAfter) {
      return NULL;
    }

    $state->status = 'fail';
    $state->detail = $obs->detail;

    if ($this->config->isSnoozed($obs->name)) {
      $state->clearNotified();
      return NULL;
    }

    $state->markNotified($this->config->now);
    return $this->message($obs->name, 'fail', $obs->detail, renotify: FALSE);
  }

  /**
   * Recovered: close the loop, but only if we opened it loudly.
   *
   * @param \Civi\Herald\Monitor\CheckState $state The working state.
   * @param \Civi\Herald\Monitor\Observation $obs The normalized observation.
   * @return array<string, mixed>|null The message to publish, if any.
   */
  private function close(CheckState $state, Observation $obs): ?array {
    $wasNotified = $state->notified;
    $state->status = 'ok';
    $state->detail = $obs->detail;
    $state->clearNotified();

    return $wasNotified ? $this->message($obs->name, 'ok', $obs->detail, renotify: FALSE) : NULL;
  }

  /**
   * Still inside a fail episode: delayed first alert, or a renotify reminder.
   *
   * Both alert paths require the CURRENT observation to be a fail: while
   * close_after hysteresis rides out the first ok observations of a recovery,
   * announcing (or re-announcing) a fail during those ok runs would be wrong —
   * the incident may be over.
   *
   * @param \Civi\Herald\Monitor\CheckState $state The working state.
   * @param \Civi\Herald\Monitor\Observation $obs The normalized observation.
   * @return array<string, mixed>|null The message to publish, if any.
   */
  private function whileFailing(CheckState $state, Observation $obs): ?array {
    // Keep the freshest fail detail.
    if ($obs->failed) {
      $state->detail = $obs->detail;
    }

    if (!$obs->failed || $this->config->isSnoozed($obs->name)) {
      return NULL;
    }

    if (!$state->notified) {
      // The fail was suppressed by a snooze that has since lapsed — announce
      // it now (delayed first alert).
      $state->markNotified($this->config->now);
      return $this->message($obs->name, 'fail', $state->detail, renotify: FALSE);
    }

    if (!$this->renotifyDue($state)) {
      return NULL;
    }

    // Stuck failing past the renotify window: re-announce.
    $state->markNotified($this->config->now);
    return $this->message($obs->name, 'fail', $state->detail, renotify: TRUE);
  }

  /**
   * Has the renotify window elapsed since the last fail alert?
   *
   * @param \Civi\Herald\Monitor\CheckState $state The working state.
   * @return bool TRUE if a reminder is due.
   */
  private function renotifyDue(CheckState $state): bool {
    return (
      $this->config->renotifySeconds > 0
      && $state->lastNotified !== NULL
      && ($this->config->now - $state->lastNotified) >= $this->config->renotifySeconds
    );
  }

  /**
   * Build the publish vars for one transition.
   *
   * @param string $name The check name.
   * @param string $status The reported status.
   * @param string $detail The detail to show.
   * @param bool $renotify Is this a reminder rather than a fresh edge?
   * @return array<string, mixed> The message vars.
   */
  private function message(string $name, string $status, string $detail, bool $renotify): array {
    return ['check' => $name, 'status' => $status, 'detail' => $detail, 'renotify' => $renotify];
  }

}
