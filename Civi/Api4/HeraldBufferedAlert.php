<?php

declare(strict_types = 1);

namespace Civi\Api4;

/**
 * Read-only view of the alert buffer, for SearchKit. Rows are written and
 * drained by Civi\Herald\Buffer\AlertBuffer; parked rows return to circulation
 * only through Herald.reactivateParkedAlerts, which `reactivate` calls.
 */
final class HeraldBufferedAlert extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   * @return Generic\DAOGetAction
   */
  public static function get($checkPermissions = TRUE) {
    return (new Generic\DAOGetAction(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function reactivate(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\ReactivateBufferedAlerts {
    return (new \Civi\Herald\Api4\Action\ReactivateBufferedAlerts(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\DAOGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\DAOGetFieldsAction(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @return array<string, mixed>
   */
  public static function getInfo() {
    $info = parent::getInfo();
    // DAOGetAction makes this a DAO entity for SearchKit.
    $types = is_array($info['type'] ?? NULL) ? $info['type'] : [];
    $types[0] = 'DAOEntity';
    $info['type'] = $types;
    return $info;
  }

  /**
   * @return array<string, array<int, string|array<int, string>>>
   */
  public static function permissions(): array {
    return [
      'meta' => ['administer CiviCRM'],
      'default' => ['administer CiviCRM'],
    ];
  }

}
