<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

/**
 * Reads an APIv4 field out of a row that holds raw column values, such as the
 * copy CiviRules keeps of a record before an edit.
 */
final class StoredRow {

  private const UNKNOWN = ['known' => FALSE, 'value' => NULL];

  /**
   * An option suffix such as :name is resolved from the raw value of its base field.
   *
   * @param array<array-key, mixed> $row
   * @return array{known: bool, value: mixed}
   */
  public static function value(string $entity, string $field, array $row): array {
    if (array_key_exists($field, $row)) {
      return ['known' => TRUE, 'value' => $row[$field]];
    }
    $parts = explode(':', $field, 2);
    if (count($parts) !== 2 || !array_key_exists($parts[0], $row)) {
      return self::UNKNOWN;
    }
    [$name, $suffix] = $parts;
    $options = civicrm_api4($entity, 'getFields', [
      'checkPermissions' => FALSE,
      'loadOptions' => ['id', $suffix],
      'where' => [['name', '=', $name]],
      'select' => ['options'],
    ])->first()['options'] ?? NULL;
    if (!is_array($options)) {
      return self::UNKNOWN;
    }
    $names = array_column($options, $suffix, 'id');
    $raw = $row[$name];
    $ids = is_string($raw) && str_contains($raw, \CRM_Core_DAO::VALUE_SEPARATOR)
      ? \CRM_Utils_Array::explodePadded($raw)
      : $raw;
    $resolve = static fn (mixed $id): mixed => is_int($id) || is_string($id) ? $names[$id] ?? NULL : NULL;
    return ['known' => TRUE, 'value' => is_array($ids) ? array_map($resolve, array_values($ids)) : $resolve($ids)];
  }

}
