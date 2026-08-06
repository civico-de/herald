<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Herald\PreEditSnapshot;

/**
 * hook_civicrm_pre helper: snapshot the pre-edit values of every field the
 * generic field-transition condition watches on the edited entity.
 *
 * Reads via APIv4 so custom fields and pseudo-fields resolve the same way the
 * condition later reads the post-edit value. A failed read only skips the
 * snapshot — the condition then falls back to CiviRules' original data.
 */
final class FieldTransitionSnapshotter {

  public static function capture(string $entity, int $id): void {
    $fields = FieldTransitionWatchList::fieldsFor($entity);
    if ($fields === [] || $id <= 0) {
      return;
    }
    try {
      $row = civicrm_api4($entity, 'get', [
        'checkPermissions' => FALSE,
        'select' => $fields,
        'where' => [['id', '=', $id]],
      ])->first();
    }
    catch (\Throwable $e) {
      \Civi::log()->warning('herald: field-transition pre-edit snapshot failed', [
        'entity' => $entity,
        'id' => $id,
        'error' => $e->getMessage(),
      ]);
      return;
    }
    if (!is_array($row)) {
      return;
    }
    foreach ($fields as $field) {
      if (!array_key_exists($field, $row)) {
        continue;
      }
      PreEditSnapshot::remember($entity, $id, $field, $row[$field]);
    }
  }

  /**
   * Post-edit value of one field read from the live row; ['known' => FALSE]
   * when the row or field is unreadable.
   *
   * @return array{known: bool, value: mixed}
   */
  public static function currentValue(string $entity, int $id, string $field): array {
    try {
      $row = civicrm_api4($entity, 'get', [
        'checkPermissions' => FALSE,
        'select' => [$field],
        'where' => [['id', '=', $id]],
      ])->first();
    }
    catch (\Throwable) {
      return ['known' => FALSE, 'value' => NULL];
    }
    if (!is_array($row) || !array_key_exists($field, $row)) {
      return ['known' => FALSE, 'value' => NULL];
    }
    return ['known' => TRUE, 'value' => $row[$field]];
  }

}
