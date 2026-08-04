<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * One check's persisted state machine entry.
 *
 * Schema (old entries without the extra keys are tolerated — status defaults
 * to 'ok', counters to 0, notified inferred from the status):
 *   status: 'ok'|'fail'  — last REPORTED state (the edge we alerted on)
 *   detail: string
 *   consecutive_fails / consecutive_oks: int  — raw observation run-lengths
 *   notified: bool        — did we actually send a fail alert this episode
 *   last_notified: int|null — timestamp of the last fail alert (for renotify)
 */
class CheckState {

  public string $status = 'ok';

  public string $detail = '';

  public int $consecutiveFails = 0;

  public int $consecutiveOks = 0;

  public bool $notified = FALSE;

  public ?int $lastNotified = NULL;

  /**
   * Read a persisted entry, tolerating legacy shapes.
   *
   * @param array<string, mixed> $p The persisted entry, possibly empty.
   * @param int $now Timestamp used to anchor migrated entries.
   * @return self The restored state.
   */
  public static function fromArray(array $p, int $now): self {
    $state = new self();
    $state->status = ($p['status'] ?? 'ok') === 'fail' ? 'fail' : 'ok';
    $state->detail = Coerce::str($p['detail'] ?? NULL);
    $state->consecutiveFails = max(0, Coerce::int($p['consecutive_fails'] ?? 0));
    $state->consecutiveOks = max(0, Coerce::int($p['consecutive_oks'] ?? 0));
    // Migration: legacy entries (bare {status, detail}) carry no 'notified'
    // flag. The old monitor always announced a fail, so infer a legacy
    // reported-fail as already-notified — otherwise it would re-alert or,
    // worse, swallow the recovery.
    $state->notified = (bool) ($p['notified'] ?? $state->status === 'fail');
    $state->lastNotified = isset($p['last_notified']) && is_numeric($p['last_notified'])
      ? (int) $p['last_notified']
      : NULL;
    // An inferred already-notified fail carries no timestamp — without one,
    // renotify would never fire for it. Anchor it to now so the first reminder
    // comes one full window from migration.
    if ($state->notified && $state->lastNotified === NULL) {
      $state->lastNotified = $now;
    }

    return $state;
  }

  /**
   * The entry to persist.
   *
   * @return array<string, mixed> The serialized entry.
   */
  public function toArray(): array {
    return [
      'status' => $this->status,
      'detail' => $this->detail,
      'consecutive_fails' => $this->consecutiveFails,
      'consecutive_oks' => $this->consecutiveOks,
      'notified' => $this->notified,
      'last_notified' => $this->lastNotified,
    ];
  }

  /**
   * Advance the raw run-length counters with a failing observation.
   */
  public function countFail(): void {
    $this->consecutiveFails++;
    $this->consecutiveOks = 0;
  }

  /**
   * Advance the raw run-length counters with a passing observation.
   */
  public function countOk(): void {
    $this->consecutiveOks++;
    $this->consecutiveFails = 0;
  }

  /**
   * Record that a fail alert went out.
   *
   * @param int $now Timestamp of the alert.
   */
  public function markNotified(int $now): void {
    $this->notified = TRUE;
    $this->lastNotified = $now;
  }

  /**
   * Record that no alert is outstanding for this check.
   */
  public function clearNotified(): void {
    $this->notified = FALSE;
    $this->lastNotified = NULL;
  }

}
