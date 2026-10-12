<?php

declare(strict_types = 1);

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
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
class ContributionStatsService {

  private SourceNormalizer $sourceNormalizer;

  public function __construct(?SourceNormalizer $sourceNormalizer = NULL) {
    $this->sourceNormalizer = $sourceNormalizer ?? new SourceNormalizer();
  }

  /**
   * Period summary: total amount + count, grouped by source.
   *
   * @param HeraldFilters $filters
   *
   * @return array{total: float, count: int, bySource: array<int, array{source: string, total: float, count: int}>}
   * @mago-expect lint:halstead
   */
  public function getPeriodSummary(array $filters, DateRange $period): array {
    $where = $this->buildWhere($filters, $this->statusOf($filters, 'Completed'));
    $where[] = ['receive_date', '>=', $period->startSql()];
    $where[] = ['receive_date', '<', $period->endSql()];

    /** @var array<int, array{total: float|int|numeric-string|null, cnt: int|numeric-string|null, source: string|null}> $rows */
    $rows = $this
      ->contributionGet($where)
      ->addSelect('SUM(total_amount) AS total', 'COUNT(*) AS cnt', 'source')
      ->addGroupBy('source')
      ->execute()
      ->getArrayCopy();

    if (!$this->sourceNormalizer->isEnabled()) {
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

    $total = 0.0;
    $count = 0;
    $bySource = [];
    foreach ($rows as $row) {
      $rowTotal = (float) ($row['total'] ?? 0);
      $rowCount = (int) ($row['cnt'] ?? 0);
      $total += $rowTotal;
      $count += $rowCount;
      $source = $row['source'] ?: 'Unknown';
      $source = $this->sourceNormalizer->normalize($source);
      if (!isset($bySource[$source])) {
        $bySource[$source] = ['source' => $source, 'total' => 0.0, 'count' => 0];
      }
      $bySource[$source]['total'] += $rowTotal;
      $bySource[$source]['count'] += $rowCount;
    }
    return ['total' => $total, 'count' => $count, 'bySource' => array_values($bySource)];
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
      $period,
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
      $period,
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
  public function getRecentStatusTransitions(
    array $filters,
    int $minutes,
    string $toStatus,
    // @mago-expect lint:no-boolean-flag-parameter
    // Public signature of an existing API; changing it would break callers.
    bool $excludeAlsoReceivedInPeriod = TRUE,
  ): array {
    $where = $this->buildWhere($filters, $toStatus);
    $period = DateRange::lastMinutes($minutes);
    $where[] = ['modified_date', '>=', $period->startSql()];
    if ($excludeAlsoReceivedInPeriod) {
      $where[] = ['receive_date', '<', $period->startSql()];
    }

    /** @var array<int, array{total_amount: float|int|numeric-string|null, source: string|null, receive_date: string|null, modified_date: string|null}> $rows */
    $rows = $this
      ->contributionGet($where)
      ->addSelect('total_amount', 'source', 'receive_date', 'modified_date')
      ->addOrderBy('modified_date', 'DESC')
      ->execute()
      ->getArrayCopy();

    return array_map(
      fn ($r) => [
        'amount' => (float) ($r['total_amount'] ?? 0),
        'source' => $this->sourceNormalizer->normalize($r['source'] ?: 'Unknown'),
        'date' => $r['modified_date'] ?? $r['receive_date'] ?? '',
      ],
      $rows,
    );
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
  public function getStatusTransitionsInPeriod(
    array $filters,
    DateRange $period,
    string $toStatus,
    // @mago-expect lint:no-boolean-flag-parameter
    // Public signature of an existing API; changing it would break callers.
    bool $excludeAlsoReceivedInPeriod = TRUE,
  ): array {
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
   * Total + count of the completed contributions matching the filters, at any
   * date: campaign progress counts toward the goal across the whole campaign.
   *
   * @param HeraldFilters $filters
   *
   * @return array{total: float, count: int}
   */
  public function getCampaignTotal(array $filters): array {
    return $this->fetchTotalAndCount($this->buildWhere($filters, 'Completed'));
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
   * Raw amounts of the contributions matching the filters in the period, for
   * histogram input.
   *
   * @param HeraldFilters $filters
   *
   * @return float[]
   */
  public function getAmountDistribution(array $filters, DateRange $period): array {
    $where = $this->buildWhere($filters, $this->statusOf($filters, 'Completed'));
    $where[] = ['receive_date', '>=', $period->startSql()];
    $where[] = ['receive_date', '<', $period->endSql()];

    /** @var array<int, array{total_amount: float|int|numeric-string|null}> $rows */
    $rows = $this
      ->contributionGet($where)
      ->addSelect('total_amount')
      ->setLimit(0)
      ->execute()
      ->getArrayCopy();

    return array_values(array_map(static fn ($r) => (float) ($r['total_amount'] ?? 0), $rows));
  }

  /**
   * Line-item summary: qty + total grouped by (label, unit_price) — "what was
   * actually sold", independent of how contributions bundle items (carts).
   *
   * Filters mirror buildWhere(), but financial_type_id applies to the LINE
   * ITEM's own financial type: in a mixed cart (ticket + pass in one
   * contribution) each item counts toward its own block. 0-amount lines
   * (code redemptions, free seats) are excluded. `$period` NULL = no date
   * bound (outstanding pool, matches getAllPending()).
   *
   * @param HeraldFilters $filters
   *
   * @return array<int, array{label: string, unit_price: float, qty: float|int, total: float}>
   */
  public function getItemSummary(array $filters, ?DateRange $period, ?string $status = NULL): array {
    $where = [
      ['contribution_id.contribution_status_id:name', '=', $status ?? $this->statusOf($filters, 'Completed')],
      ['line_total', '>', 0],
      // Same restriction as contributionGet(), expressed on the joined
      // contribution: test drives and recurring templates are not sales.
      ['contribution_id.is_test', '=', FALSE],
      ['contribution_id.is_template', '=', FALSE],
    ];
    if (!empty($filters['campaign_id'])) {
      $where[] = ['contribution_id.campaign_id', '=', (int) self::asScalar($filters['campaign_id'])];
    }
    if (!empty($filters['financial_type_id'])) {
      $where[] = ['financial_type_id', '=', (int) self::asScalar($filters['financial_type_id'])];
    }
    if (!empty($filters['source_pattern'])) {
      $where[] = ['contribution_id.source', 'LIKE', $filters['source_pattern']];
    }
    if ($period !== NULL) {
      $where[] = ['contribution_id.receive_date', '>=', $period->startSql()];
      $where[] = ['contribution_id.receive_date', '<', $period->endSql()];
    }

    /** @var array<int, array{label: string|null, unit_price: float|int|numeric-string|null, qty_sum: float|int|numeric-string|null, total: float|int|numeric-string|null}> $rows */
    $rows = \Civi\Api4\LineItem::get(checkPermissions: FALSE)
      // Alias must differ from the field name (Api4SelectQuery restriction).
      ->addSelect('label', 'unit_price', 'SUM(qty) AS qty_sum', 'SUM(line_total) AS total')
      ->setWhere($where)
      ->addGroupBy('label')
      ->addGroupBy('unit_price')
      ->addOrderBy('total', 'DESC')
      ->execute()
      ->getArrayCopy();

    return array_map(
      static function ($r) {
        $qty = (float) ($r['qty_sum'] ?? 0);
        return [
          'label' => $r['label'] ?? '' ?: 'Unknown',
          'unit_price' => (float) ($r['unit_price'] ?? 0),
          // Whole quantities render as "6×", not "6.00×".
          // @mago-expect lint:identity-comparison
          // Deliberate: float vs int check — `===` would never match a whole quantity.
          'qty' => $qty == (int) $qty ? (int) $qty : $qty,
          'total' => (float) ($r['total'] ?? 0),
        ];
      },
      $rows,
    );
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
   * Filter values arrive untyped from CiviRules storage; arrays/objects are
   * never valid filter values.
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
    $rows = $this
      ->contributionGet($where)
      ->addSelect('SUM(total_amount) AS total', 'COUNT(*) AS cnt')
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
    $rows = $this
      ->contributionGet($where)
      ->addSelect('total_amount', 'source', 'receive_date')
      ->addOrderBy('receive_date', 'DESC')
      ->execute()
      ->getArrayCopy();

    return array_map(
      fn ($r) => [
        'amount' => (float) ($r['total_amount'] ?? 0),
        'source' => $this->sourceNormalizer->normalize($r['source'] ?: 'Unknown'),
        'date' => $r['receive_date'] ?? '',
      ],
      $rows,
    );
  }

  /**
   * APIv4 `Contribution.get` with permission checks disabled, restricted to
   * real contributions.
   *
   * The restriction lives here rather than in buildWhere() because APIv4 — no
   * `api.default` like APIv3 — applies neither filter on its own: a forgotten
   * one would count contribution-page test drives and the is_template row of
   * every recurring contribution as income. The where-clauses go in through
   * this method for the same reason (setWhere() would drop them).
   *
   * `Civi\Api4\Contribution` is shipped by core's bundled `civi_contribute`
   * extension rather than by Civi/Api4 proper; phpstan.neon.dist scans
   * core/ext so it resolves like any other entity.
   *
   * @param HeraldWhere $where
   */
  private function contributionGet(array $where = []): \Civi\Api4\Generic\DAOGetAction {
    return \Civi\Api4\Contribution::get(checkPermissions: FALSE)
      ->setWhere($where)
      ->addWhere('is_test', '=', value: FALSE)
      ->addWhere('is_template', '=', value: FALSE);
  }

}
