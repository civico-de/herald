<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Aggregate queries against civicrm_contribution for digest and alert
 * templates, narrowed by ContributionFilters.
 *
 * Aggregate columns use SQL-style aliases (`SUM(total_amount) AS total`) since
 * APIv4's `SUM:field` shorthand returns empty values.
 *
 * @phpstan-type HeraldFilters array<string, mixed>
 * @phpstan-type HeraldWhere array<int, mixed>
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
   */
  public function getPeriodSummary(array $filters, DateRange $period): array {
    $where = ContributionFilters::where($filters, 'Completed');
    $where[] = ['receive_date', '>=', $period->startSql()];
    $where[] = ['receive_date', '<', $period->endSql()];

    /** @var array<int, array{total: float|int|numeric-string|null, cnt: int|numeric-string|null, source: string|null}> $rows */
    $rows = $this
      ->contributionGet($where)
      ->addSelect('SUM(total_amount) AS total', 'COUNT(*) AS cnt', 'source')
      ->addGroupBy('source')
      ->execute()
      ->getArrayCopy();

    $bySource = [];
    foreach ($rows as $row) {
      $source = $this->sourceNormalizer->normalize($row['source'] ?: 'Unknown');
      $bySource[$source] ??= ['source' => $source, 'total' => 0.0, 'count' => 0];
      $bySource[$source]['total'] += Cast::toFloat($row['total']);
      $bySource[$source]['count'] += Cast::toInt($row['cnt']);
    }
    return [
      'total' => (float) array_sum(array_column($bySource, 'total')),
      'count' => (int) array_sum(array_column($bySource, 'count')),
      'bySource' => array_values($bySource),
    ];
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
    return $this->fetchTotalAndCount(ContributionFilters::where($filters, 'Completed'));
  }

  /**
   * All pending contributions regardless of age — the outstanding pool.
   *
   * @param HeraldFilters $filters
   *
   * @return array{total: float, count: int}
   */
  public function getAllPending(array $filters): array {
    return $this->fetchTotalAndCount(ContributionFilters::where($filters, 'Pending'));
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
    $where = ContributionFilters::where($filters, 'Completed');
    $where[] = ['receive_date', '>=', $period->startSql()];
    $where[] = ['receive_date', '<', $period->endSql()];

    /** @var array<int, array{total_amount: float|int|numeric-string|null}> $rows */
    $rows = $this
      ->contributionGet($where)
      ->addSelect('total_amount')
      ->setLimit(0)
      ->execute()
      ->getArrayCopy();

    return array_values(array_map(static fn ($r) => Cast::toFloat($r['total_amount']), $rows));
  }

  /**
   * Line-item summary: qty + total grouped by (label, unit_price) — "what was
   * actually sold", independent of how contributions bundle items (carts).
   *
   * Filters as ContributionFilters reads them, but financial_type_id applies to the LINE
   * ITEM's own financial type: in a mixed cart (ticket + pass in one
   * contribution) each item counts toward its own block. 0-amount lines
   * (code redemptions, free seats) are excluded. `$period` NULL = no date
   * bound (outstanding pool, matches getAllPending()).
   *
   * @param HeraldFilters $filters
   *
   * @return array<int, array{label: string, unit_price: float, qty: float|int, total: float}>
   */
  public function getItemSummary(array $filters, ?DateRange $period, string $status = 'Completed'): array {
    $where = ContributionFilters::where($filters, $status, 'contribution_id.');
    // Same restriction as contributionGet(), on the joined contribution: test
    // drives and recurring templates are not sales.
    $where[] = ['line_total', '>', 0];
    $where[] = ['contribution_id.is_test', '=', FALSE];
    $where[] = ['contribution_id.is_template', '=', FALSE];
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
        $qty = Cast::toFloat($r['qty_sum']);
        return [
          'label' => Cast::toString($r['label']) ?: 'Unknown',
          'unit_price' => Cast::toFloat($r['unit_price']),
          // Whole quantities render as "6×", not "6.00×".
          'qty' => fmod($qty, 1.0) === 0.0 ? (int) $qty : $qty,
          'total' => Cast::toFloat($r['total']),
        ];
      },
      $rows,
    );
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
   * APIv4 `Contribution.get` with permission checks disabled, restricted to
   * real contributions.
   *
   * The restriction lives here rather than in ContributionFilters because APIv4 — no
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
