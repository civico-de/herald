<?php

namespace Civi\Herald\Buffer;

/**
 * Anti-spam aggregation buffer for realtime alerts.
 *
 * When a CiviRules send action has aggregate_minutes > 0, the rendered alert
 * body is parked here instead of being sent immediately. The scheduled job
 * Job.herald_buffer_flush (run_frequency "Always") drains the buffer: a group
 * becomes due once its OLDEST entry is older than the configured window, and
 * everything collected for that group by then goes out as ONE message.
 *
 * Grouping — all entries written by the same rule action (identical action
 * params → identical group_hash) aggregate together; two different rules or
 * two differently-configured actions never mix.
 */
class AlertBuffer {

  public const TABLE = 'civicrm_herald_buffer';

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
        PRIMARY KEY (`id`),
        KEY `index_group_hash` (`group_hash`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
  }

  /**
   * Park one rendered alert body for later aggregated delivery.
   *
   * @param array<string, mixed> $delivery Full action params — everything
   *   AbstractSend::deliver() needs to send the composed message later.
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
        4 => [json_encode($delivery, JSON_UNESCAPED_UNICODE) ?: '{}', 'String'],
      ] + ($amount === NULL ? [] : [5 => [(string) $amount, 'Money']]),
    );
  }

  /**
   * Groups whose oldest entry has waited at least its window.
   *
   * Rows are NOT deleted here — the caller deletes via deleteRows() only
   * after the composed message was sent, so a failed send retries on the
   * next cron run instead of silently dropping alerts.
   *
   * @return list<array{group_hash: string, window: int, ids: list<int>, bodies: list<string>, amounts: list<float>, delivery: array<string, mixed>}>
   */
  public function claimDue(): array {
    // executeQuery() is documented as CRM_Core_DAO|object — the `object` half of
    // that union exists only so IDEs stop flagging the result-row properties,
    // which the DAO sets dynamically. Spell the SELECT list out instead: the
    // annotation narrows the union AND documents the columns each loop reads.
    /** @var \CRM_Core_DAO&object{group_hash: string} $due */
    $due = \CRM_Core_DAO::executeQuery('
      SELECT group_hash
      FROM `' . self::TABLE . '`
      GROUP BY group_hash
      HAVING MIN(created_date) <= DATE_SUB(NOW(), INTERVAL MAX(window_minutes) MINUTE)
    ');
    $groups = [];
    while ($due->fetch()) {
      $groupHash = self::str($due->group_hash);
      /** @var \CRM_Core_DAO&object{id: string, window_minutes: string, body: string, delivery: string, amount: ?string} $rows */
      $rows = \CRM_Core_DAO::executeQuery(
        'SELECT id, window_minutes, body, delivery, amount FROM `'
        . self::TABLE
        . '` WHERE group_hash = %1 ORDER BY id',
        [1 => [$groupHash, 'String']],
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
        if (is_array($delivery)) {
          // Last writer wins — all entries in a group carry the same params.
          // add() encodes an array<string, mixed>; the keys survive the round trip.
          /** @var array<string, mixed> $deliveryParams */
          $deliveryParams = $delivery;
        }
      }
      if ($ids) {
        $groups[] = [
          'group_hash' => $groupHash,
          'window' => $window,
          'ids' => $ids,
          'bodies' => $bodies,
          'amounts' => $amounts,
          'delivery' => $deliveryParams,
        ];
      }
    }
    return $groups;
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
    $bodies = array_values(array_filter(array_map('trim', $bodies), static fn (string $b) => $b !== ''));
    if (count($bodies) <= 1) {
      return $bodies[0] ?? '';
    }
    $header = '📬 <b>' . self::t('%1 messages', [1 => count($bodies)]) . '</b>';
    if ($amounts) {
      $header .= ', ' . self::t('total %1', [1 => '<b>' . self::money(array_sum($amounts)) . '</b>']);
    }
    $header .= ' (' . self::t('collected over %1 min', [1 => $windowMinutes]) . ')';
    return $header . "\n\n" . implode("\n\n", $bodies);
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

  /**
   * Locale- and currency-correct money formatting (default currency of the
   * instance) when Civi is booted; a fixed EUR/German rendering in pure unit
   * tests, matching this extension's origin.
   */
  private static function money(float $amount): string {
    if (class_exists('\Civi') && is_callable(['\Civi', 'format'])) {
      try {
        return \Civi::format()->money($amount);
      }
      catch (\Throwable $e) {
        // Fall through to the static rendering.
      }
    }
    return number_format($amount, 2, ',', '.') . ' €';
  }

  /**
   * Translate through the civix ExtensionUtil (herald's own domain) when Civi
   * is booted; plain %N substitution otherwise (pure unit tests, no framework).
   *
   * @param array<int, string|int> $params
   */
  private static function t(string $text, array $params = []): string {
    if (function_exists('ts') && class_exists(\CRM_Herald_ExtensionUtil::class)) {
      return \CRM_Herald_ExtensionUtil::ts($text, $params);
    }
    foreach ($params as $key => $value) {
      $text = str_replace('%' . $key, (string) $value, $text);
    }
    return $text;
  }

}
