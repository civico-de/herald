<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * A shipped digest search as an old block counted: the search itself when the
 * block had no filter and its period, otherwise a copy with the block's filters and period.
 */
final class CRM_Herald_Upgrader_SearchCopy {

  /**
   * The id of the table display to use.
   *
   * @param ?string $period An old period, or NULL for none
   * @param list<array{0: string, 1: string, 2: mixed}> $filters Contribution fields
   */
  public static function of(string $name, ?string $period, array $filters, string $source, string $label): int {
    $shipped = self::shipped($name);
    $field = $shipped['api_entity'] === 'LineItem' ? 'contribution_id.receive_date' : 'receive_date';
    $where = is_array($shipped['api_params']['where'] ?? NULL) ? $shipped['api_params']['where'] : [];
    $undated = array_values(array_filter(
      $where,
      static fn (mixed $clause): bool => !is_array($clause) || ($clause[0] ?? NULL) !== $field,
    ));
    // The shipped searches count yesterday, or have no period.
    $asShipped = count($undated) === count($where) ? $period === NULL : $period === 'yesterday';
    if ($filters === [] && $asShipped) {
      return $shipped['display_id'];
    }
    if ($period !== NULL) {
      $undated[] = CRM_Herald_Upgrader_InlinePeriod::where($field, $period);
    }
    foreach ($filters as $filter) {
      $undated[] = $shipped['api_entity'] === 'LineItem'
        ? CRM_Herald_Upgrader_LineItemSearch::filter($filter)
        : $filter;
    }
    return self::create(
      "{$label}: {$shipped['label']} ({$source})",
      $shipped['api_entity'],
      ['where' => $undated] + $shipped['api_params'],
      $shipped['settings'],
    );
  }

  /**
   * A saved search with one table display; returns the display's id.
   *
   * @param array<array-key, mixed> $params
   * @param array<array-key, mixed> $settings
   */
  public static function create(string $label, string $entity, array $params, array $settings): int {
    // Labels hold 255 characters; a rule label can fill them alone.
    $label = mb_substr($label, 0, 255);
    $search = \Civi\Api4\SavedSearch::create(checkPermissions: FALSE)
      ->setValues(['label' => $label, 'api_entity' => $entity, 'api_params' => ['version' => 4] + $params])
      ->execute()
      ->single();
    return Cast::toInt(
      \Civi\Api4\SearchDisplay::create(checkPermissions: FALSE)
        ->setValues(['saved_search_id' => $search['id'], 'label' => $label, 'type' => 'table', 'settings' => $settings])
        ->execute()
        ->single()['id'],
    );
  }

  /**
   * @return array{display_id: int, label: string, api_entity: string, api_params: array<array-key, mixed>, settings: array<array-key, mixed>}
   */
  private static function shipped(string $name): array {
    $display = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addSelect('id', 'settings', 'saved_search_id.label', 'saved_search_id.api_entity', 'saved_search_id.api_params')
      ->addWhere('name', '=', "{$name}_Table")
      ->execute()
      ->first() ?? throw new CRM_Core_Exception("the shipped search {$name} is missing; is CiviContribute enabled?");
    return [
      'display_id' => Cast::toInt($display['id']),
      'label' => Cast::toString($display['saved_search_id.label']),
      'api_entity' => Cast::toString($display['saved_search_id.api_entity']),
      'api_params' => is_array($display['saved_search_id.api_params']) ? $display['saved_search_id.api_params'] : [],
      'settings' => is_array($display['settings']) ? $display['settings'] : [],
    ];
  }

}
