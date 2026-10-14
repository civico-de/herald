<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Api4\Utils\FormattingUtil;

/**
 * Reads an APIv4 field out of a stored copy of a record, such as the APIv3 or
 * APIv4 get result CiviRules keeps from before an edit.
 */
final class StoredRow {

  private const UNKNOWN = ['known' => FALSE, 'value' => NULL];

  /**
   * Serialized values and option suffixes such as :name resolve as APIv4 get
   * resolves them, disabled options included.
   *
   * @param array<array-key, mixed> $row
   * @return array{known: bool, value: mixed}
   */
  public static function value(string $entity, string $field, array $row): array {
    $name = FormattingUtil::removeSuffix($field);
    if (!array_key_exists($name, $row)) {
      return self::UNKNOWN;
    }
    try {
      $spec = civicrm_api4($entity, 'getFields', [
        'checkPermissions' => FALSE,
        'where' => [['name', '=', $name]],
        'select' => ['name', 'entity', 'serialize'],
      ])->first();
      if (!is_array($spec)) {
        return self::UNKNOWN;
      }
      $stored = $row[$name];
      $raw = $spec['serialize'] && is_string($stored)
        ? \CRM_Core_DAO::unSerializeField($stored, $spec['serialize'])
        : $stored;
      if ($raw === NULL || $name === $field) {
        return ['known' => TRUE, 'value' => $raw];
      }
      $keys = is_array($raw) ? array_map(self::optionKey(...), $raw) : self::optionKey($raw);
      return [
        'known' => TRUE,
        'value' => FormattingUtil::replacePseudoconstant(
          FormattingUtil::getPseudoconstantList($spec, $field, $row),
          $keys,
        ),
      ];
    }
    catch (\CRM_Core_Exception) {
      return self::UNKNOWN;
    }
  }

  private static function optionKey(mixed $value): string {
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    if (is_scalar($value)) {
      return (string) $value;
    }
    throw new \CRM_Core_Exception('An option key must be a scalar.');
  }

}
