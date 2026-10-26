<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * Pure state machine (no Civi calls; now/thresholds/snooze come via $config).
 *
 * A key seen for the first time baselines to reported-ok. A key in the state
 * without an observation this run counts as ok. A key is muted while core
 * hides its message or herald snoozes it; muted failures change the state
 * without an alert, while a recovery after an announced failure is always sent.
 *
 * @phpstan-type Plan array{check: string, state: ?array<string, mixed>, publish: ?array<string, mixed>}
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
   * A plan's state is NULL when the key has nothing left to remember.
   *
   * @param array<string, array<string, mixed>> $prev
   * @param array<string, array<string, mixed>> $observations
   * @return list<Plan>
   */
  public function plan(array $prev, array $observations): array {
    $failing = array_filter($observations, static fn (array $obs): bool => ($obs['status'] ?? 'ok') === 'fail');
    $plans = [];
    foreach (array_keys($failing + $prev) as $key) {
      $plans[] = $this->planCheck((string) $key, $observations[$key] ?? ['status' => 'ok'], $prev[$key] ?? []);
    }

    return $plans;
  }

  /**
   * @param array<string, mixed> $obs
   * @param array<string, mixed> $prevEntry
   * @return Plan
   */
  private function planCheck(string $key, array $obs, array $prevEntry): array {
    $observation = Observation::fromArray($key, $obs);
    $state = CheckState::fromArray($prevEntry, $this->config->now);

    // Counters advance before the decision; the reported status does not, so
    // decide() can still read the episode we were in.
    $observation->failed ? $state->countFail() : $state->countOk();
    $publish = $this->decide($state, $observation);

    return ['check' => $key, 'state' => $state->persisted(), 'publish' => $publish];
  }

  /**
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
   * Muted: state flips but stays unannounced, so a later recovery cannot
   * fire a fail→ok for a fail nobody saw.
   *
   * @return array<string, mixed>|null The message to publish, if any.
   */
  private function maybeOpen(CheckState $state, Observation $obs): ?array {
    if ($state->consecutiveFails < $this->config->openAfter) {
      return NULL;
    }

    $state->status = 'fail';

    if ($this->config->mutes($obs)) {
      $state->clearNotified();
      return NULL;
    }

    return $this->report($state, $obs, 'fail', renotify: FALSE);
  }

  /**
   * Recovery is announced only if the fail was, muted or not.
   *
   * @return array<string, mixed>|null The message to publish, if any.
   */
  private function close(CheckState $state, Observation $obs): ?array {
    $wasNotified = $state->notified;
    $title = $obs->finding->titleOr($state->title);
    $state->status = 'ok';
    $state->clearNotified();

    return $wasNotified ? $obs->message('ok', $title, renotify: FALSE) : NULL;
  }

  /**
   * Alerts only on a currently failing, unmuted observation: during
   * close_after hysteresis the incident may already be over.
   *
   * @return array<string, mixed>|null The message to publish, if any.
   */
  private function whileFailing(CheckState $state, Observation $obs): ?array {
    if (!$obs->failed) {
      return NULL;
    }

    if ($this->config->mutes($obs)) {
      $state->muted = TRUE;
      return NULL;
    }

    if (!$state->notified) {
      // Muting ended before the fail was ever announced.
      return $this->report($state, $obs, 'fail', renotify: FALSE);
    }

    $update = $obs->finding->updateOver($state);
    if ($update !== NULL) {
      return $this->report($state, $obs, $update, renotify: FALSE);
    }

    if (!$this->config->renotifyDue($state->lastNotified)) {
      return NULL;
    }

    return $this->report($state, $obs, 'fail', renotify: TRUE);
  }

  /**
   * @return array<string, mixed>
   */
  private function report(CheckState $state, Observation $obs, string $status, bool $renotify): array {
    $state->markNotified($this->config->now, $obs->finding);
    return $obs->message($status, $obs->finding->title, $renotify);
  }

}
