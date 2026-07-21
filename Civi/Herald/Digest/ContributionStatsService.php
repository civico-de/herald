<?php

namespace Civi\Herald\Digest;

/**
 * Aggregate queries against civicrm_contribution for digest/alert templates.
 *
 * Generic — no campaign / financial-type / source defaults. Callers pass a
 * `$filters` shape:
 *   ['campaign_id' => int, 'financial_type_id' => int, 'status' => string,
 *    'source_pattern' => string ('LIKE' pattern, e.g. "%Bank TX%")]
 * All keys are optional except 'status' (which defaults per method).
 *
 * Aggregate columns use SQL-style aliases (`SUM(total_amount) AS total`) since
 * APIv4's `SUM:field` shorthand returns empty values.
 *
 * @phpstan-type HeraldFilters array<string, mixed>
 * @phpstan-type HeraldWhere array<int, mixed>
 */
class ContributionStatsService {

  /**
   * Period summary: total amount + count, grouped by source.
   *
   * @param HeraldFilters $filters
   *
   * @return array{total: float, count: int, bySource: array<int, array{source: string, total: float, count: int}>}
   */
  public function getPeriodSummary(array $filters, DateRange $period): array {
    $where = $this->buildWhere($filters, $this->statusOf($filters, 'Completed'));
    $where[] = ['receive_date', '>=', $period->startSql()];
    $where[] = ['receive_date', '<', $period->endSql()];

    /** @var array<int, array{total: float|int|numeric-string|null, cnt: int|numeric-string|null, source: string|null}> $rows */
    $rows = $this->contributionGet()
      ->addSelect('SUM(total_amount) AS total', 'COUNT(*) AS cnt', 'source')
      ->setWhere($where)
      ->addGroupBy('source')
      ->execute()
      ->getArrayCopy();

    $total = 0.0;
    $count = 0;
    $bySource = [];
    foreach ($rows as $row) {
      $rowTotal = (float) ($row['total'] ?? 0);
      $rowCount = (int) ($row['cnt'] ?? 0);
      $total += $rowTotal;
      $count += $rowCount;
      $bySource[] = [
        'source' => $row['source'] ?: 'Unknown',
        'total' => $rowTotal,
        'count' => $rowCount,
      ];
    }
    return ['total' => $total, 'count' => $count, 'bySource' => $bySource];
  }

  /**
   * Completed contributions whose receive_date is within the last N minutes.
   *
   * @param HeraldFilters $filters
   *
   * @return array<int, array{amount: float, source: string, date: string}>
   */
  public function getRecentCompleted(array $filters, int $minutes): array {
    $period = DateRange::lastMinutes($minutes);
    return $this->fetchRecentByReceiveDate(
      $this->buildWhere($filters, $this->statusOf($filters, 'Completed')),
      $period
    );
  }

  /**
   * Pending contributions whose receive_date is within the last N minutes.
   *
   * @param HeraldFilters $filters
   *
   * @return array<int, array{amount: float, source: string, date: string}>
   */
  public function getRecentPending(array $filters, int $minutes): array {
    $period = DateRange::lastMinutes($minutes);
    return $this->fetchRecentByReceiveDate(
      $this->buildWhere($filters, $this->statusOf($filters, 'Pending')),
      $period
    );
  }

  /**
   * Contributions whose modified_date is within the last N minutes and which now
   * have status `$toStatus`. Detects status transitions like Pending→Completed
   * without depending on a specific source pattern (the caller can add one via
   * `$filters['source_pattern']`).
   *
   * By default rows whose receive_date also falls inside the window are
   * excluded — those were just created, not transitioned (same heuristic as
   * getStatusTransitionsInPeriod()).
   *
   * @param HeraldFilters $filters
   *
   * @return array<int, array{amount: float, source: string, date: string}>
   */
  public function getRecentStatusTransitions(array $filters, int $minutes, string $toStatus, bool $excludeAlsoReceivedInPeriod = TRUE): array {
    $where = $this->buildWhere($filters, $toStatus);
    $period = DateRange::lastMinutes($minutes);
    $where[] = ['modified_date', '>=', $period->startSql()];
    if ($excludeAlsoReceivedInPeriod) {
      $where[] = ['receive_date', '<', $period->startSql()];
    }

    /** @var array<int, array{total_amount: float|int|numeric-string|null, source: string|null, receive_date: string|null, modified_date: string|null}> $rows */
    $rows = $this->contributionGet()
      ->addSelect('total_amount', 'source', 'receive_date', 'modified_date')
      ->setWhere($where)
      ->addOrderBy('modified_date', 'DESC')
      ->execute()
      ->getArrayCopy();

    return array_map(static fn($r) => [
      'amount' => (float) ($r['total_amount'] ?? 0),
      'source' => $r['source'] ?: 'Unknown',
      'date' => $r['modified_date'] ?? $r['receive_date'] ?? '',
    ], $rows);
  }

  /**
   * Status transitions whose modified_date falls inside `$period`. Optionally
   * excludes rows whose receive_date is also inside the period — these are
   * already counted by getPeriodSummary() and would double-count.
   *
   * @param HeraldFilters $filters
   *
   * @return array{total: float, count: int}
   */
  public function getStatusTransitionsInPeriod(array $filters, DateRange $period, string $toStatus, bool $excludeAlsoReceivedInPeriod = TRUE): array {
    $where = $this->buildWhere($filters, $toStatus);
    $where[] = ['modified_date', '>=', $period->startSql()];
    $where[] = ['modified_date', '<', $period->endSql()];
    if ($excludeAlsoReceivedInPeriod) {
      $where[] = [
        'OR',
        [
          ['receive_date', '<', $period->startSql()],
          ['receive_date', '>=', $period->endSql()],
        ],
      ];
    }

    return $this->fetchTotalAndCount($where);
  }

  /**
   * Total + count across all completed contributions for a campaign.
   *
   * @return array{total: float, count: int}
   */
  public function getCampaignTotal(int $campaignId): array {
    return $this->fetchTotalAndCount([
      ['contribution_status_id:name', '=', 'Completed'],
      ['campaign_id', '=', $campaignId],
    ]);
  }

  /**
   * Pending snapshot constrained to a date range (e.g. transfers announced yesterday).
   *
   * @param HeraldFilters $filters
   *
   * @return array{total: float, count: int}
   */
  public function getPendingSnapshot(array $filters, DateRange $period): array {
    $where = $this->buildWhere($filters, $this->statusOf($filters, 'Pending'));
    $where[] = ['receive_date', '>=', $period->startSql()];
    $where[] = ['receive_date', '<', $period->endSql()];
    return $this->fetchTotalAndCount($where);
  }

  /**
   * All pending contributions regardless of age — the outstanding pool.
   *
   * @param HeraldFilters $filters
   *
   * @return array{total: float, count: int}
   */
  public function getAllPending(array $filters): array {
    $where = $this->buildWhere($filters, $this->statusOf($filters, 'Pending'));
    return $this->fetchTotalAndCount($where);
  }

  /**
   * Raw amounts of all contributions matching the filters, for histogram input.
   *
   * @param HeraldFilters $filters
   *
   * @return float[]
   */
  public function getAmountDistribution(array $filters): array {
    $where = $this->buildWhere($filters, $this->statusOf($filters, 'Completed'));

    /** @var array<int, array{total_amount: float|int|numeric-string|null}> $rows */
    $rows = $this->contributionGet()
      ->addSelect('total_amount')
      ->setWhere($where)
      ->setLimit(0)
      ->execute()
      ->getArrayCopy();

    return array_values(array_map(static fn($r) => (float) ($r['total_amount'] ?? 0), $rows));
  }

  /**
   * Translate a $filters array to APIv4 where-clauses, with the requested status
   * applied as `contribution_status_id:name`.
   *
   * @param HeraldFilters $filters
   *
   * @return HeraldWhere
   */
  private function buildWhere(array $filters, string $status): array {
    $where = [['contribution_status_id:name', '=', $status]];
    if (!empty($filters['campaign_id'])) {
      $where[] = ['campaign_id', '=', (int) self::asScalar($filters['campaign_id'])];
    }
    if (!empty($filters['financial_type_id'])) {
      $where[] = ['financial_type_id', '=', (int) self::asScalar($filters['financial_type_id'])];
    }
    if (!empty($filters['source_pattern'])) {
      $where[] = ['source', 'LIKE', $filters['source_pattern']];
    }
    return $where;
  }

  /**
   * @param HeraldFilters $filters
   */
  private function statusOf(array $filters, string $default): string {
    return (string) self::asScalar($filters['status'] ?? $default);
  }

  /**
   * Filter values arrive untyped (CiviRules stores action parameters as raw
   * form input), so phpstan sees `mixed` where the code casts to int/string.
   * Narrowing to `scalar` keeps PHP's own cast semantics for every value that
   * can actually occur here (int, float, numeric string, bool) and only
   * differs for arrays/objects, which are never valid filter values.
   *
   * @param mixed $value
   */
  private static function asScalar($value): string|int|float|bool {
    return is_scalar($value) ? $value : '';
  }

  /**
   * Run a SUM/COUNT query for the given where-clauses and unwrap the single row.
   *
   * @param HeraldWhere $where
   *
   * @return array{total: float, count: int}
   */
  private function fetchTotalAndCount(array $where): array {
    /** @var array<int, array{total: float|int|numeric-string|null, cnt: int|numeric-string|null}> $rows */
    $rows = $this->contributionGet()
      ->addSelect('SUM(total_amount) AS total', 'COUNT(*) AS cnt')
      ->setWhere($where)
      ->execute()
      ->getArrayCopy();

    $row = $rows[0] ?? [];
    return [
      'total' => (float) ($row['total'] ?? 0),
      'count' => (int) ($row['cnt'] ?? 0),
    ];
  }

  /**
   * Common query for "recent contributions" — returns flat rows with the typed shape.
   *
   * @param HeraldWhere $where
   *
   * @return array<int, array{amount: float, source: string, date: string}>
   */
  private function fetchRecentByReceiveDate(array $where, DateRange $period): array {
    $where[] = ['receive_date', '>=', $period->startSql()];

    /** @var array<int, array{total_amount: float|int|numeric-string|null, source: string|null, receive_date: string|null}> $rows */
    $rows = $this->contributionGet()
      ->addSelect('total_amount', 'source', 'receive_date')
      ->setWhere($where)
      ->addOrderBy('receive_date', 'DESC')
      ->execute()
      ->getArrayCopy();

    return array_map(static fn($r) => [
      'amount' => (float) ($r['total_amount'] ?? 0),
      'source' => $r['source'] ?: 'Unknown',
      'date' => $r['receive_date'] ?? '',
    ], $rows);
  }

  /**
   * APIv4 `Contribution.get` with permission checks disabled.
   *
   * `Civi\Api4\Contribution` is shipped by core's bundled `civi_contribute`
   * extension rather than by Civi/Api4 proper; phpstan.neon.dist scans
   * core/ext so it resolves like any other entity.
   */
  private function contributionGet(): \Civi\Api4\Generic\DAOGetAction {
    return \Civi\Api4\Contribution::get(FALSE);
  }

}
