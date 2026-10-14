<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Line-item searches for old blocks broken down by line item.
 */
final class CRM_Herald_Upgrader_LineItemSearch {

  /**
   * Sum and count of line items, as old line-item blocks totalled them: the
   * count is the quantity sold, and a mixed cart counts only its matching items.
   *
   * @param ?string $period An old period, or NULL for any date
   * @param list<array{0: string, 1: string, 2: mixed}> $filters Contribution fields
   */
  public static function totals(
    string $status,
    ?string $period,
    array $filters,
    string $source,
    string $label,
  ): int {
    $where = [
      ['contribution_id.contribution_status_id:name', '=', $status],
      ['contribution_id.is_test', '=', FALSE],
      ['contribution_id.is_template', '=', FALSE],
      ['line_total', '>', 0],
    ];
    if ($period !== NULL) {
      $where[] = CRM_Herald_Upgrader_InlinePeriod::where('contribution_id.receive_date', $period);
    }
    foreach ($filters as $filter) {
      $where[] = self::filter($filter);
    }
    return CRM_Herald_Upgrader_SearchCopy::create(
      "{$label}: " . E::ts('line item totals') . " ({$source})",
      'LineItem',
      ['select' => ['SUM(line_total) AS SUM_line_total', 'SUM(qty) AS SUM_qty'], 'where' => $where],
      [
        'columns' => [
          ['type' => 'field', 'key' => 'SUM_line_total', 'label' => E::ts('Sum')],
          ['type' => 'field', 'key' => 'SUM_qty', 'label' => E::ts('Count')],
        ],
      ],
    );
  }

  /**
   * A contribution filter on a line item; the financial type is the item's own.
   *
   * @param array{0: string, 1: string, 2: mixed} $filter
   * @return array{0: string, 1: string, 2: mixed}
   */
  public static function filter(array $filter): array {
    [$field, $operator, $value] = $filter;
    return [$field === 'financial_type_id' ? $field : "contribution_id.{$field}", $operator, $value];
  }

}
