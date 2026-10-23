<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Api4\Utils\FormattingUtil;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Reads an APIv4 field out of the values one edit wrote, as CiviRules copies
 * them from the DAO.
 */
final class WrittenRow {

  /**
   * Reads a field out of the values an edit wrote, as CiviRules copies them from
   * the DAO, after bringing them to the form an APIv4 get returns.
   *
   * @param array<array-key, mixed> $row
   * @return array{known: bool, value: mixed}
   */
  public static function value(string $entity, string $field, array $row): array {
    $name = FormattingUtil::removeSuffix($field);
    if (array_key_exists($name, $row)) {
      $row[$name] = self::asRead($entity, $name, $row[$name]);
    }
    return StoredRow::value($entity, $field, $row);
  }

  /**
   * A DAO keeps text HTML-encoded and may hold a date as YmdHis.
   */
  private static function asRead(string $entity, string $name, mixed $value): mixed {
    if (!is_string($value)) {
      return $value;
    }
    $coder = \CRM_Utils_API_HTMLInputCoder::singleton();
    $value = $coder->isSkippedField($name) ? $value : Cast::toString($coder->decodeValue($value));
    $type = FieldSpec::get($entity, $name, ['data_type'])['data_type'] ?? NULL;
    if (in_array($type, ['Date', 'Timestamp'], TRUE) && preg_match('/^\d+$/', $value) === 1) {
      $value = FormattingUtil::convertDataType(\CRM_Utils_Date::mysqlToIso($value), $type);
    }
    return $value;
  }

}
