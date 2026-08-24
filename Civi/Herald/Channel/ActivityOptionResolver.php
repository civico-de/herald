<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Resolves stable option names to installation-local numeric values.
 */
final class ActivityOptionResolver {

  public static function resolve(string $groupName, string $optionName): int {
    $row = \Civi\Api4\OptionValue::get(checkPermissions: FALSE)
      ->addSelect('value')
      ->addWhere('option_group_id:name', '=', $groupName)
      ->addWhere('name', '=', $optionName)
      ->addWhere('is_active', '=', value: TRUE)
      ->execute()
      ->first();
    $value = $row['value'] ?? NULL;
    if (!is_numeric($value) || (int) $value <= 0) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: activity channel option "%s" does not exist in option group "%s".',
        $optionName,
        $groupName,
      ));
    }
    return (int) $value;
  }

}
