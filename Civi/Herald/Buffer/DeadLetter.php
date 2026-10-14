<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Api4\HeraldBufferedAlert;
use Civi\Herald\Api4\Rows;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Dead-letter side of the alert buffer.
 *
 * A buffered group that cannot be delivered must neither retry forever nor
 * vanish: it is parked in place (parked_at set), which takes it out of
 * AlertBuffer::claimDue() while keeping the row — and its last error —
 * readable via getParked(). Only reactivate() puts it back in circulation.
 *
 * Two ways in: a permanent fault (a fan-out that resolves to no recipient)
 * parks at once, everything else after recordFailure() has burned through the
 * configured attempt budget.
 */
class DeadLetter {

  private AlertBuffer $buffer;

  public function __construct(?AlertBuffer $buffer = NULL) {
    $this->buffer = $buffer ?? new AlertBuffer();
  }

  /**
   * Decide what a failed flush means for the rows it touched.
   *
   * A fan-out that resolved to nobody is a configuration fault: the next run
   * would resolve the same empty list, so it parks at once. Everything else is
   * treated as possibly transient and gets $maxAttempts tries.
   *
   * @param list<int> $ids
   * @return bool TRUE when the rows were parked.
   */
  public function handleFailure(array $ids, \Throwable $error, int $maxAttempts): bool {
    if ($error instanceof \Civi\Herald\Dispatch\NoRecipientsException) {
      $this->park($ids, 'no_recipients', $error->getMessage());
      return TRUE;
    }
    return $this->recordFailure($ids, $error->getMessage(), $maxAttempts);
  }

  /**
   * Record a failed flush attempt and decide the row's fate: park the rows
   * once they have burned through $maxAttempts, otherwise hand them back for
   * the next run.
   *
   * @param list<int> $ids
   * @return bool TRUE when the rows were parked.
   */
  public function recordFailure(array $ids, string $error, int $maxAttempts): bool {
    if (!$ids) {
      return FALSE;
    }
    // The rows are this run's claim, so reading and writing back the counter
    // cannot race another run.
    $idsByAttempts = [];
    foreach (Rows::of(
      HeraldBufferedAlert::get(checkPermissions: FALSE)
        ->addSelect('id', 'attempts')
        ->addWhere('id', 'IN', $ids)
        ->execute(),
    ) as $row) {
      $idsByAttempts[Cast::toInt($row['attempts']) + 1][] = Cast::toInt($row['id']);
    }
    foreach ($idsByAttempts as $next => $rowIds) {
      HeraldBufferedAlert::update(checkPermissions: FALSE)
        ->addWhere('id', 'IN', $rowIds)
        ->setValues(['attempts' => $next, 'last_error' => self::truncateError($error)])
        ->execute();
    }
    $attempts = $idsByAttempts ? max(array_keys($idsByAttempts)) : 0;
    if ($attempts >= max(1, $maxAttempts)) {
      $this->park($ids, 'max_attempts', $error);
      return TRUE;
    }
    $this->buffer->release($ids);
    return FALSE;
  }

  /**
   * Move rows to the dead-letter state: kept and visible, but no longer
   * claimed and never offered to a flush again until reactivate().
   *
   * @param list<int> $ids
   */
  public function park(array $ids, string $reason, string $error): void {
    if (!$ids) {
      return;
    }
    HeraldBufferedAlert::update(checkPermissions: FALSE)
      ->addWhere('id', 'IN', $ids)
      ->setValues([
        'claimed_by' => NULL,
        'claimed_at' => NULL,
        'parked_at' => 'now',
        'park_reason' => substr($reason, 0, 64),
        'last_error' => self::truncateError($error),
      ])
      ->execute();
  }

  /**
   * Deliberately put parked rows back into circulation, attempt counter reset
   * so a fixed configuration gets a full budget again.
   *
   * @param list<int> $ids Empty = every parked row.
   * @return int Rows reactivated.
   */
  public function reactivate(array $ids = []): int {
    $update = HeraldBufferedAlert::update(checkPermissions: FALSE)
      ->addWhere('parked_at', 'IS NOT NULL')
      ->setValues([
        'parked_at' => NULL,
        'park_reason' => NULL,
        'attempts' => 0,
        'claimed_by' => NULL,
        'claimed_at' => NULL,
      ]);
    if ($ids) {
      $update->addWhere('id', 'IN', $ids);
    }
    return $update->execute()->count();
  }

  /**
   * Parked rows for inspection — bodies stay out: they can carry contact data,
   * and the dead-letter view answers "what is stuck and why".
   *
   * @param list<int> $ids Empty = every parked row.
   * @return list<array{id: int, group_hash: string, created_date: string, parked_at: string, park_reason: string, attempts: int, last_error: string}>
   */
  public function getParked(array $ids = []): array {
    $get = HeraldBufferedAlert::get(checkPermissions: FALSE)
      ->addSelect('id', 'group_hash', 'created_date', 'parked_at', 'park_reason', 'attempts', 'last_error')
      ->addWhere('parked_at', 'IS NOT NULL')
      ->addOrderBy('id');
    if ($ids) {
      $get->addWhere('id', 'IN', $ids);
    }
    $out = [];
    foreach (Rows::of($get->execute()) as $row) {
      $out[] = [
        'id' => Cast::toInt($row['id']),
        'group_hash' => Cast::toString($row['group_hash']),
        'created_date' => Cast::toString($row['created_date']),
        'parked_at' => Cast::toString($row['parked_at']),
        'park_reason' => Cast::toString($row['park_reason']),
        'attempts' => Cast::toInt($row['attempts']),
        'last_error' => Cast::toString($row['last_error']),
      ];
    }
    return $out;
  }

  /**
   * Keep a stored error inside the column and out of log-flooding territory.
   */
  private static function truncateError(string $error): string {
    return mb_substr($error, 0, 500);
  }

}
