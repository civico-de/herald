<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

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
    $idList = implode(',', array_map('intval', $ids));
    \CRM_Core_DAO::executeQuery(
      'UPDATE `' . AlertBuffer::TABLE . '` SET attempts = attempts + 1, last_error = %1 WHERE id IN (' . $idList . ')',
      [1 => [self::truncateError($error), 'String']],
    );
    $exhausted = (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT MAX(attempts) FROM `' . AlertBuffer::TABLE . '` WHERE id IN (' . $idList . ')',
    ) >= max(1, $maxAttempts);
    if ($exhausted) {
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
    \CRM_Core_DAO::executeQuery(
      'UPDATE `' . AlertBuffer::TABLE . '` SET claimed_by = NULL, claimed_at = NULL, parked_at = NOW(), park_reason = %1, last_error = %2
         WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')',
      [1 => [substr($reason, 0, 64), 'String'], 2 => [self::truncateError($error), 'String']],
    );
  }

  /**
   * Deliberately put parked rows back into circulation, attempt counter reset
   * so a fixed configuration gets a full budget again.
   *
   * @param list<int> $ids Empty = every parked row.
   * @return int Rows reactivated.
   */
  public function reactivate(array $ids = []): int {
    $where = $ids ? ' AND id IN (' . implode(',', array_map('intval', $ids)) . ')' : '';
    // Counted before the UPDATE: the affected-row count of a DAO query is not
    // part of the typed API surface.
    $count = (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM `' . AlertBuffer::TABLE . '` WHERE parked_at IS NOT NULL' . $where,
    );
    \CRM_Core_DAO::executeQuery(
      'UPDATE `' . AlertBuffer::TABLE . '` SET parked_at = NULL, park_reason = NULL, attempts = 0, claimed_by = NULL, claimed_at = NULL
         WHERE parked_at IS NOT NULL' . $where,
    );
    return $count;
  }

  /**
   * Parked rows for inspection — bodies stay out: they can carry contact data,
   * and the dead-letter view answers "what is stuck and why".
   *
   * @param list<int> $ids Empty = every parked row.
   * @return list<array{id: int, group_hash: string, created_date: string, parked_at: string, park_reason: string, attempts: int, last_error: string}>
   */
  public function getParked(array $ids = []): array {
    $where = $ids ? ' AND id IN (' . implode(',', array_map('intval', $ids)) . ')' : '';
    /** @var \CRM_Core_DAO&object{id: string, group_hash: string, created_date: string, parked_at: string, park_reason: ?string, attempts: string, last_error: ?string} $rows */
    $rows = \CRM_Core_DAO::executeQuery(
      'SELECT id, group_hash, created_date, parked_at, park_reason, attempts, last_error
       FROM `' . AlertBuffer::TABLE . '` WHERE parked_at IS NOT NULL' . $where . ' ORDER BY id',
    );
    $out = [];
    while ($rows->fetch()) {
      $out[] = [
        'id' => self::int($rows->id),
        'group_hash' => self::str($rows->group_hash),
        'created_date' => self::str($rows->created_date),
        'parked_at' => self::str($rows->parked_at),
        'park_reason' => self::str($rows->park_reason),
        'attempts' => self::int($rows->attempts),
        'last_error' => self::str($rows->last_error),
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

  /**
   * Coerce a DAO result-row value (dynamic property, therefore untyped) to
   * string; every column read here is declared scalar.
   */
  private static function str(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Coerce a DAO result-row value to int. See self::str().
   */
  private static function int(mixed $value): int {
    return is_scalar($value) ? (int) $value : 0;
  }

}
