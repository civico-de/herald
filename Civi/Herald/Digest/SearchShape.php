<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\Query\Api4SelectQuery;
use Civi\Api4\Query\SqlExpression;
use Civi\Api4\Query\SqlFunction;
use Civi\API\Request;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * How SearchKit reads the search behind a display: the data type of each
 * selected key, whether it groups, and whether it yields one row, aggregating
 * without grouping.
 */
final class SearchShape {

  /**
   * @param array<string, mixed> $display A display with the name of its search
   * @return array{types: array<string, ?string>, single: bool, grouped: bool}
   */
  public static function of(array $display): array {
    $search = \Civi\Api4\SavedSearch::get(checkPermissions: FALSE)
      ->addSelect('api_entity', 'api_params')
      ->addWhere('name', '=', Cast::toString($display['saved_search_id.name']))
      ->execute()
      ->single();
    $params = (array) $search['api_params'];
    $api = Request::create(Cast::toString($search['api_entity']), 'get', ['checkPermissions' => FALSE] + $params);
    $query = $api instanceof DAOGetAction ? new Api4SelectQuery($api) : NULL;
    $types = [];
    $grouped = ($params['groupBy'] ?? []) !== [];
    $single = !$grouped;
    foreach ((array) ($params['select'] ?? []) as $select) {
      $expr = SqlExpression::convert(Cast::toString($select), TRUE);
      $single = $single && $expr instanceof SqlFunction && $expr::getCategory() === SqlFunction::CATEGORY_AGGREGATE;
      // The function's type, else that of its first field; a suffix such as :label makes the field text.
      $field = explode(':', Cast::toString($expr->getFields()[0] ?? ''));
      $types[$expr->getAlias()] =
        $expr::getDataType() ?? (isset($field[1]) ? 'String' : self::fieldType($query, $field[0]));
    }
    return ['types' => $types, 'single' => $single && $types !== [], 'grouped' => $grouped];
  }

  private static function fieldType(?Api4SelectQuery $query, string $field): ?string {
    if ($query === NULL || $field === '') {
      return NULL;
    }
    $spec = $query->getField($field);
    return is_array($spec) && is_string($spec['data_type'] ?? NULL) ? $spec['data_type'] : NULL;
  }

}
