<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

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
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
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
   * Idempotent DDL, used from the Upgrader (install + upgrade step).
   */
  public static function createTable(): void {
    \CRM_Core_DAO::executeQuery('
      CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `group_hash` varchar(64) NOT NULL,
        `window_minutes` int unsigned NOT NULL DEFAULT 5,
        `amount` decimal(20,2) DEFAULT NULL,
        `body` longtext NOT NULL,
        `delivery` longtext NOT NULL,
        `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `claimed_by` varchar(64) DEFAULT NULL,
        `claimed_at` timestamp NULL DEFAULT NULL,
        `attempts` int unsigned NOT NULL DEFAULT 0,
        `parked_at` timestamp NULL DEFAULT NULL,
        `park_reason` varchar(64) DEFAULT NULL,
        `last_error` text DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `index_group_hash` (`group_hash`),
        KEY `index_claimed_by` (`claimed_by`),
        KEY `index_parked_at` (`parked_at`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
    self::addClaimColumns();
    self::addDeadLetterColumns();
  }

  /**
   * Claim columns for a table created before flush claiming existed — CREATE
   * TABLE IF NOT EXISTS leaves an existing table untouched.
   */
  public static function addClaimColumns(): void {
    if (\CRM_Core_BAO_SchemaHandler::checkIfFieldExists(self::TABLE, 'claimed_by')) {
      return;
    }
    \CRM_Core_DAO::executeQuery(
      'ALTER TABLE `' . self::TABLE . '`
        ADD COLUMN `claimed_by` varchar(64) DEFAULT NULL,
        ADD COLUMN `claimed_at` timestamp NULL DEFAULT NULL,
        ADD KEY `index_claimed_by` (`claimed_by`)',
    );
  }

  /**
   * Dead-letter columns for a table created before parking existed — CREATE
   * TABLE IF NOT EXISTS leaves an existing table untouched.
   */
  public static function addDeadLetterColumns(): void {
    if (\CRM_Core_BAO_SchemaHandler::checkIfFieldExists(self::TABLE, 'parked_at')) {
      return;
    }
    \CRM_Core_DAO::executeQuery(
      'ALTER TABLE `' . self::TABLE . '`
        ADD COLUMN `attempts` int unsigned NOT NULL DEFAULT 0,
        ADD COLUMN `parked_at` timestamp NULL DEFAULT NULL,
        ADD COLUMN `park_reason` varchar(64) DEFAULT NULL,
        ADD COLUMN `last_error` text DEFAULT NULL,
        ADD KEY `index_parked_at` (`parked_at`)',
    );
  }

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
    // executeQuery() is documented as CRM_Core_DAO|object — the `object` half of
    // that union exists only so IDEs stop flagging the result-row properties,
    // which the DAO sets dynamically. Spell the SELECT list out instead: the
    // annotation narrows the union AND documents the columns each loop reads.
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
      $hashes[] = self::str($due->group_hash);
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
      $ids[] = self::int($rows->id);
      $bodies[] = self::str($rows->body);
      if ($rows->amount !== NULL) {
        $amounts[] = self::flt($rows->amount);
      }
      $window = max($window, self::int($rows->window_minutes));
      $delivery = json_decode(self::str($rows->delivery), TRUE);
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

  /**
   * Compose the outgoing message from buffered bodies. Pure — test-seam.
   *
   * A single buffered alert goes out unchanged (aggregation only added
   * latency, not noise). Multiple alerts get a summary header — count and,
   * when amounts were recorded, their sum — followed by the individual
   * bodies.
   *
   * @param list<string> $bodies
   * @param list<float> $amounts
   */
  public static function compose(array $bodies, int $windowMinutes, array $amounts = []): string {
    $bodies = AggregateSummary::nonBlank($bodies);
    if (count($bodies) <= 1) {
      return $bodies[0] ?? '';
    }
    return AggregateSummary::line(count($bodies), $windowMinutes, $amounts, 'b') . "\n\n" . implode("\n\n", $bodies);
  }

  /**
   * Delivery params for the composed message. Pure — test-seam.
   *
   * An aggregate gets a subject describing the aggregate: every buffered row
   * carries the subject its own event rendered, so keeping one of them would
   * name a message of N events after a single one.
   *
   * @param array<string, mixed> $delivery
   * @param list<string> $bodies
   * @param list<float> $amounts
   * @return array<string, mixed>
   */
  public static function composeDelivery(
    array $delivery,
    array $bodies,
    int $windowMinutes,
    array $amounts = [],
  ): array {
    $count = count(AggregateSummary::nonBlank($bodies));
    if ($count > 1) {
      $delivery['email_subject'] = AggregateSummary::line($count, $windowMinutes, $amounts);
    }
    return $delivery;
  }

  /**
   * Coerce a DAO result-row value (dynamic property, therefore untyped) to
   * string. Every column read here is declared scalar in createTable(), so the
   * default only ever guards against the theoretical mixed type.
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

  /**
   * Coerce a DAO result-row value to float. See self::str().
   */
  private static function flt(mixed $value): float {
    return is_scalar($value) ? (float) $value : 0.0;
  }

}
