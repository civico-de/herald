<?php

declare(strict_types = 1);

namespace Civi\Herald\Cleaning;

use Civi\Api4\Utils\CoreUtil;
use CRM_Herald_ExtensionUtil as E;

/**
 * A field that can be cleaned: a text column of a database entity, since the
 * cleaned twin is computed in SQL from that column.
 */
final class TextField {

  private const TEXT_TYPES = ['String', 'Text'];

  public static function problem(string $entity, string $field): ?string {
    if ($entity === '' || !CoreUtil::entityExists($entity) || !CoreUtil::isType($entity, 'DAOEntity')) {
      return E::ts('choose a record type.');
    }
    $spec = $field === '' ? NULL : civicrm_api4($entity, 'getFields', [
        'checkPermissions' => FALSE,
        'action' => 'get',
        'where' => [['name', '=', $field], ['type', '=', 'Field']],
        'select' => ['data_type'],
      ])->first();
    return (
      in_array($spec['data_type'] ?? NULL, self::TEXT_TYPES, TRUE)
        ? NULL
        : E::ts('choose a text field of %1.', [1 => $entity])
    );
  }

}
