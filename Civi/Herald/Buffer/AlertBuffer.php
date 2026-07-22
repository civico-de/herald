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
  public function add(string $groupHash, int $windowMinutes, string $body, array $delivery, ?float $amount = NULL): void {
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO `' . self::TABLE . '` (group_hash, window_minutes, body, delivery, amount) VALUES (%1, %2, %3, %4, ' . ($amount === NULL ? 'NULL' : '%5') . ')',
      [
        1 => [$groupHash, 'String'],
        2 => [max(1, $windowMinutes), 'Integer'],
        3 => [$body, 'String'],
        4 => [json_encode($delivery, JSON_UNESCAPED_UNICODE) ?: '{}', 'String'],
      ] + ($amount === NULL ? [] : [5 => [(string) $amount, 'Money']])
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
    $due = \CRM_Core_DAO::executeQuery('
      SELECT group_hash
      FROM `' . self::TABLE . '`
      GROUP BY group_hash
      HAVING MIN(created_date) <= DATE_SUB(NOW(), INTERVAL MAX(window_minutes) MINUTE)
    ');
    $groups = [];
    while ($due->fetch()) {
      $rows = \CRM_Core_DAO::executeQuery(
        'SELECT id, window_minutes, body, delivery, amount FROM `' . self::TABLE . '` WHERE group_hash = %1 ORDER BY id',
        [1 => [(string) $due->group_hash, 'String']]
      );
      $group = ['group_hash' => (string) $due->group_hash, 'window' => 0, 'ids' => [], 'bodies' => [], 'amounts' => [], 'delivery' => []];
      while ($rows->fetch()) {
        $group['ids'][] = (int) $rows->id;
        $group['bodies'][] = (string) $rows->body;
        if ($rows->amount !== NULL) {
          $group['amounts'][] = (float) $rows->amount;
        }
        $group['window'] = max($group['window'], (int) $rows->window_minutes);
        $delivery = json_decode((string) $rows->delivery, TRUE);
        if (is_array($delivery)) {
          // Last writer wins — all entries in a group carry the same params.
          $group['delivery'] = $delivery;
        }
      }
      if ($group['ids']) {
        $groups[] = $group;
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
      'DELETE FROM `' . self::TABLE . '` WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'
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
    $bodies = array_values(array_filter(array_map('trim', $bodies), static fn(string $b) => $b !== ''));
    if (count($bodies) <= 1) {
      return $bodies[0] ?? '';
    }
    $header = '📬 <b>' . self::t('%1 messages', [1 => count($bodies)]) . '</b>';
    if ($amounts) {
      $header .= ', ' . self::t('total %1', [1 => '<b>' . number_format(array_sum($amounts), 2, ',', '.') . ' €</b>']);
    }
    $header .= ' (' . self::t('collected over %1 min', [1 => $windowMinutes]) . ')';
    return $header . "\n\n" . implode("\n\n", $bodies);
  }

  /**
   * Translate through Civi's ts() with the herald domain when Civi is booted;
   * plain %N substitution otherwise (pure unit tests, no framework).
   *
   * @param array<int, string|int> $params
   */
  private static function t(string $text, array $params = []): string {
    if (function_exists('ts')) {
      return ts($text, $params + ['domain' => 'herald']);
    }
    foreach ($params as $key => $value) {
      $text = str_replace('%' . $key, (string) $value, $text);
    }
    return $text;
  }

}
