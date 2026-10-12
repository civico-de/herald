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

  public static function capture(string $objectName, int $id): void {
    $watches = FieldTransitionWatchList::watchesFor($objectName);
    if ($watches === [] || $id <= 0) {
      return;
    }
    // Group by configured entity spelling: it is both the APIv4 entity to
    // read and the snapshot key the condition later looks up.
    $byEntity = [];
    foreach ($watches as $watch) {
      $byEntity[$watch['entity']][] = $watch['field'];
    }
    foreach ($byEntity as $entity => $fields) {
      self::snapshotEntityFields($entity, $id, $fields);
    }
  }

  /**
   * @param string[] $fields
   */
  private static function snapshotEntityFields(string $entity, int $id, array $fields): void {
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
