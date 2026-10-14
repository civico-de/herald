<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * Digest filters as APIv4 where-clauses. Keys, all optional: campaign_id,
 * financial_type_id, source_pattern (a LIKE pattern such as "%Bank TX%");
 * an empty value applies no filter.
 *
 * @phpstan-type HeraldFilters array<string, mixed>
 * @phpstan-type HeraldWhere array<int, mixed>
 */
final class ContributionFilters {

  /**
   * APIv4 where-clauses for the filters and status. $prefix reaches the
   * contribution from a joined entity; financial_type_id always applies to
   * the queried entity itself, so a line item counts toward its own type.
   *
   * @param HeraldFilters $filters
   *
   * @return HeraldWhere
   */
  public static function where(array $filters, string $status, string $prefix = ''): array {
    $where = [[$prefix . 'contribution_status_id:name', '=', $status]];
    if (!empty($filters['campaign_id'])) {
      $where[] = [$prefix . 'campaign_id', '=', (int) self::asScalar($filters['campaign_id'])];
    }
    if (!empty($filters['financial_type_id'])) {
      $where[] = ['financial_type_id', '=', (int) self::asScalar($filters['financial_type_id'])];
    }
    if (!empty($filters['source_pattern'])) {
      $where[] = [$prefix . 'source', 'LIKE', $filters['source_pattern']];
    }
    return $where;
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

}
