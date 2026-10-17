<?php

declare(strict_types = 1);

namespace Civi\Herald\Cleaning;

/**
 * The cleaned fields of one record, read through APIv4 so notifications and
 * searches share one SQL expression.
 */
final class CleanedValues {

  /**
   * By the name of the raw field; [] when the entity has none. A failed read
   * is logged and leaves the raw values, so a presentation setting cannot break alerts.
   *
   * @return array<string, mixed>
   */
  public static function of(string $entity, int $id): array {
    $entries = CleanedFields::forEntity($entity);
    if ($entries === [] || $id <= 0) {
      return [];
    }
    $fields = array_column($entries, 'field');
    try {
      $row = civicrm_api4($entity, 'get', [
        'checkPermissions' => FALSE,
        'select' => array_map(CleanedFields::name(...), $fields),
        'where' => [['id', '=', $id]],
      ])->first();
    }
    catch (\Throwable $e) {
      \Civi::log('herald')->error('herald: could not read cleaned fields', [
        'entity' => $entity,
        'id' => $id,
        'error' => $e->getMessage(),
      ]);
      return [];
    }
    // A deleted record leaves the trigger's own values as they are.
    if ($row === NULL) {
      return [];
    }
    $values = [];
    foreach ($fields as $field) {
      $values[$field] = $row[CleanedFields::name($field)] ?? NULL;
    }
    return $values;
  }

}
