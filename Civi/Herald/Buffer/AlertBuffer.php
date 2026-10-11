<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Anti-spam aggregation buffer for realtime alerts.
 *
 * When a send has aggregate_minutes > 0, the rendered alert
 * body is parked here instead of being sent immediately. The scheduled job
 * Job.herald_buffer_flush (run_frequency "Always") drains the buffer: a group
 * becomes due once its OLDEST entry is older than the configured window, and
 * everything collected for that group by then goes out as ONE message.
 *
 * Grouping — all entries written by the same rule action (identical action
 * params → identical group_hash) aggregate together; two different rules or
 * two differently-configured actions never mix.
 *
 * Concurrency — claimDue() stamps the rows it hands out, so two overlapping
 * cron runs can never compose and deliver the same entries twice.
 *
 * Dead letter — rows that cannot be delivered are parked rather than retried
 * forever or dropped; claimDue() skips parked rows, the rest lives in
 * DeadLetter.
 */
class AlertBuffer {

  public const TABLE = 'civicrm_herald_buffer';

  /**
   * Lock name for the flush job, mirroring the monitor's worker.herald.monitor.
   */
  public const LOCK_NAME = 'worker.herald.buffer';

  /**
   * A claim older than this belongs to a run that died between claiming and
   * deleting; its rows are offered again rather than stranded in the buffer.
   */
  private const CLAIM_TIMEOUT_MINUTES = 30;

  /**
   * Park one rendered alert body for later aggregated delivery.
   *
   * @param array<string, mixed> $delivery Full action params — everything
   *   Dispatcher::deliver() needs to send the composed message later.
   * @param float|null $amount Monetary value of the underlying event (e.g.
   *   contribution.total_amount) — summed into the aggregate header.
   */
  public function add(
    string $groupHash,
    int $windowMinutes,
    string $body,
    array $delivery,
    ?float $amount = NULL,
  ): void {
    // Serialise before touching the DB: an alert that cannot carry its
    // delivery params must not be buffered at all.
    $deliveryJson = self::encodeDelivery($delivery);
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO `'
      . self::TABLE
      . '` (group_hash, window_minutes, body, delivery, amount) VALUES (%1, %2, %3, %4, '
      . ($amount === NULL ? 'NULL' : '%5')
      . ')',
      [
        1 => [$groupHash, 'String'],
        2 => [max(1, $windowMinutes), 'Integer'],
        3 => [$body, 'String'],
        4 => [$deliveryJson, 'String'],
      ] + ($amount === NULL ? [] : [5 => [(string) $amount, 'Money']]),
    );
  }

  /**
   * Serialise the delivery params. A failure aborts the buffering: empty
   * params would send the alert to the channel defaults, not its recipient.
   *
   * @param array<string, mixed> $delivery
   */
  private static function encodeDelivery(array $delivery): string {
    try {
      return json_encode($delivery, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \CRM_Core_Exception(
        'herald: could not serialise the delivery params of a buffered alert: ' . $e->getMessage(),
        0,
        [],
        $e,
      );
    }
  }

  /**
   * Take ownership of every group whose oldest unclaimed entry has waited at
   * least its window, and return what was claimed.
   *
   * Claiming is an atomic UPDATE stamp per group, so overlapping cron runs
   * split the buffer instead of both composing — and delivering — the same
   * entries; the loser of a race simply sees no rows for that group.
   *
   * Rows are NOT deleted here: the caller deletes via deleteRows() once the
   * composed message went out, and hands them back via release() when the send
   * failed, so a failure retries on the next cron run instead of silently
   * dropping alerts.
   *
   * @return list<array{group_hash: string, window: int, ids: list<int>, bodies: list<string>, amounts: list<float>, delivery: array<string, mixed>}>
   */
  public function claimDue(): array {
    $this->releaseAbandonedClaims();
    $claim = bin2hex(random_bytes(16));
    $groups = [];
    foreach ($this->dueGroupHashes() as $groupHash) {
      \CRM_Core_DAO::executeQuery(
        'UPDATE `' . self::TABLE . '` SET claimed_by = %2, claimed_at = NOW()
           WHERE group_hash = %1 AND claimed_by IS NULL AND parked_at IS NULL',
        [1 => [$groupHash, 'String'], 2 => [$claim, 'String']],
      );
      $group = $this->readClaimed($groupHash, $claim);
      if ($group !== NULL) {
        $groups[] = $group;
      }
    }
    return $groups;
  }

  /**
   * Group hashes whose oldest UNCLAIMED entry has waited at least its window.
   *
   * @return list<string>
   */
  private function dueGroupHashes(): array {
    // The object{} shape narrows executeQuery()'s CRM_Core_DAO|object union
    // to the columns the loop reads.
    /** @var \CRM_Core_DAO&object{group_hash: string} $due */
    $due = \CRM_Core_DAO::executeQuery('
      SELECT group_hash
      FROM `' . self::TABLE . '`
      WHERE claimed_by IS NULL AND parked_at IS NULL
      GROUP BY group_hash
      HAVING MIN(created_date) <= DATE_SUB(NOW(), INTERVAL MAX(window_minutes) MINUTE)
    ');
    $hashes = [];
    while ($due->fetch()) {
      $hashes[] = Cast::toString($due->group_hash);
    }
    return $hashes;
  }

  /**
   * The rows one claim actually holds for a group, narrowed to the declared
   * types. NULL when a concurrent run claimed the group first.
   *
   * @return array{group_hash: string, window: int, ids: list<int>, bodies: list<string>, amounts: list<float>, delivery: array<string, mixed>}|null
   */
  private function readClaimed(string $groupHash, string $claim): ?array {
    /** @var \CRM_Core_DAO&object{id: string, window_minutes: string, body: string, delivery: string, amount: ?string} $rows */
    $rows = \CRM_Core_DAO::executeQuery(
      'SELECT id, window_minutes, body, delivery, amount FROM `'
      . self::TABLE
      . '` WHERE group_hash = %1 AND claimed_by = %2 ORDER BY id',
      [1 => [$groupHash, 'String'], 2 => [$claim, 'String']],
    );
    $ids = [];
    $bodies = [];
    $amounts = [];
    $window = 0;
    $deliveryParams = [];
    while ($rows->fetch()) {
      $ids[] = Cast::toInt($rows->id);
      $bodies[] = Cast::toString($rows->body);
      if ($rows->amount !== NULL) {
        $amounts[] = Cast::toFloat($rows->amount);
      }
      $window = max($window, Cast::toInt($rows->window_minutes));
      $delivery = json_decode(Cast::toString($rows->delivery), TRUE);
      if ($deliveryParams === [] && is_array($delivery)) {
        // First decodable row wins: the group's rows share the rule config, but
        // each carries the subject ITS OWN event rendered — composeDelivery()
        // replaces that for an aggregate.
        /** @var array<string, mixed> $deliveryParams */
        $deliveryParams = $delivery;
      }
    }
    if (!$ids) {
      return NULL;
    }
    return [
      'group_hash' => $groupHash,
      'window' => $window,
      'ids' => $ids,
      'bodies' => $bodies,
      'amounts' => $amounts,
      'delivery' => $deliveryParams,
    ];
  }

  /**
   * Offer rows again whose claiming run never finished (crash, killed cron);
   * without this their alerts would sit claimed in the buffer forever.
   */
  private function releaseAbandonedClaims(): void {
    \CRM_Core_DAO::executeQuery(
      'UPDATE `' . self::TABLE . '` SET claimed_by = NULL, claimed_at = NULL
         WHERE claimed_at < DATE_SUB(NOW(), INTERVAL %1 MINUTE) AND parked_at IS NULL',
      [1 => [self::CLAIM_TIMEOUT_MINUTES, 'Integer']],
    );
  }

  /**
   * Hand claimed rows back to the buffer — used when the composed message
   * could not be delivered, so the next run retries them.
   *
   * @param list<int> $ids
   */
  public function release(array $ids): void {
    if (!$ids) {
      return;
    }
    \CRM_Core_DAO::executeQuery(
      'UPDATE `'
      . self::TABLE
      . '` SET claimed_by = NULL, claimed_at = NULL WHERE id IN ('
      . implode(',', array_map('intval', $ids))
      . ')',
    );
  }

  /**
   * @param list<int> $ids
   */
  public function deleteRows(array $ids): void {
    if (!$ids) {
      return;
    }
    \CRM_Core_DAO::executeQuery(
      'DELETE FROM `' . self::TABLE . '` WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')',
    );
  }

}
