<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * One check key's persisted state machine entry.
 *
 * Schema (entries lacking keys get status 'ok', counters 0, notified inferred):
 *   status: 'ok'|'fail'  — last REPORTED state (the edge we alerted on)
 *   severity / detail / title: what the last alert reported; changes are compared to these
 *   consecutive_fails / consecutive_oks: int  — raw observation run-lengths
 *   notified: bool        — did we actually send a fail alert this episode
 *   last_notified: int|null — timestamp of the last fail alert (for renotify)
 *   muted: bool           — the failure was muted in its last failing run since the last alert
 */
class CheckState {

  public string $status = 'ok';

  public int $severity = 0;

  /**
   * @var list<string>
   */
  public array $detail = [];

  public string $title = '';

  public int $consecutiveFails = 0;

  public int $consecutiveOks = 0;

  public bool $notified = FALSE;

  public ?int $lastNotified = NULL;

  public bool $muted = FALSE;

  /**
   * @param array<string, mixed> $p The persisted entry, possibly empty.
   * @param int $now Anchor for entries that lack a last_notified timestamp.
   */
  public static function fromArray(array $p, int $now): self {
    $state = new self();
    $state->status = ($p['status'] ?? 'ok') === 'fail' ? 'fail' : 'ok';
    $state->severity = max(0, Cast::toInt($p['severity'] ?? 0));
    $state->detail = Finding::details($p['detail'] ?? []);
    $state->title = Cast::toString($p['title'] ?? NULL);
    $state->consecutiveFails = max(0, Cast::toInt($p['consecutive_fails'] ?? 0));
    $state->consecutiveOks = max(0, Cast::toInt($p['consecutive_oks'] ?? 0));
    // A bare {status, detail} entry without 'notified': treat a reported fail as
    // announced, otherwise it would re-alert or swallow the recovery.
    $state->notified = isset($p['notified']) ? !empty($p['notified']) : $state->status === 'fail';
    $state->lastNotified = isset($p['last_notified']) && is_numeric($p['last_notified'])
      ? (int) $p['last_notified']
      : NULL;
    $state->muted = !empty($p['muted']);
    // Without a timestamp renotify would never fire; anchor to now.
    if ($state->notified && $state->lastNotified === NULL) {
      $state->lastNotified = $now;
    }

    return $state;
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array {
    return [
      'status' => $this->status,
      'severity' => $this->severity,
      'detail' => $this->detail,
      'title' => $this->title,
      'consecutive_fails' => $this->consecutiveFails,
      'consecutive_oks' => $this->consecutiveOks,
      'notified' => $this->notified,
      'last_notified' => $this->lastNotified,
      'muted' => $this->muted,
    ];
  }

  /**
   * The entry to persist; NULL when reported ok with no failing run pending.
   *
   * @return array<string, mixed>|null
   */
  public function persisted(): ?array {
    return $this->status === 'ok' && $this->consecutiveFails === 0 ? NULL : $this->toArray();
  }

  public function countFail(): void {
    $this->consecutiveFails++;
    $this->consecutiveOks = 0;
  }

  public function countOk(): void {
    $this->consecutiveOks++;
    $this->consecutiveFails = 0;
  }

  /**
   * Remember what this alert reported, for the comparison in later runs.
   */
  public function markNotified(int $now, Finding $finding): void {
    $this->notified = TRUE;
    $this->lastNotified = $now;
    $this->muted = FALSE;
    $this->severity = $finding->severity;
    $this->detail = $finding->detail;
    $this->title = $finding->title;
  }

  public function clearNotified(): void {
    $this->notified = FALSE;
    $this->lastNotified = NULL;
    $this->muted = FALSE;
  }

}
