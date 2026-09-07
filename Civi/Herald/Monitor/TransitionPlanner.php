<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * Pure state machine (no Civi calls; now/thresholds/snooze come via $config).
 * A check seen for the first time baselines to reported-ok.
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
   * @param array{status: string, detail?: string} $obs
   * @param array<string, mixed> $prevEntry
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
   * Snoozed: state flips but stays unannounced, so a later recovery cannot
   * fire a fail→ok for a fail nobody saw.
   *
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
   * Recovery is announced only if the fail was.
   *
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
   * Alerts only on a currently failing observation: during close_after
   * hysteresis the incident may already be over.
   *
   * @return array<string, mixed>|null The message to publish, if any.
   */
  private function whileFailing(CheckState $state, Observation $obs): ?array {
    if ($obs->failed) {
      $state->detail = $obs->detail;
    }

    if (!$obs->failed || $this->config->isSnoozed($obs->name)) {
      return NULL;
    }

    if (!$state->notified) {
      // Snooze lapsed before the fail was ever announced.
      $state->markNotified($this->config->now);
      return $this->message($obs->name, 'fail', $state->detail, renotify: FALSE);
    }

    if (!$this->renotifyDue($state)) {
      return NULL;
    }

    $state->markNotified($this->config->now);
    return $this->message($obs->name, 'fail', $state->detail, renotify: TRUE);
  }

  private function renotifyDue(CheckState $state): bool {
    return (
      $this->config->renotifySeconds > 0
      && $state->lastNotified !== NULL
      && ($this->config->now - $state->lastNotified) >= $this->config->renotifySeconds
    );
  }

  /**
   * @return array<string, mixed>
   */
  private function message(string $name, string $status, string $detail, bool $renotify): array {
    return ['check' => $name, 'status' => $status, 'detail' => $detail, 'renotify' => $renotify];
  }

}
