<?php

declare(strict_types = 1);

namespace Civi\Api4;

/**
 * The alert buffer. Administrators may read it and `reactivate` parked rows,
 * which calls Herald.reactivateParkedAlerts; only Civi\Herald\Buffer writes.
 * New rows go through the BAO: APIv4 would HTML-encode the rendered body.
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

  /**
   * @param bool $checkPermissions
   * @return Generic\DAOUpdateAction
   */
  public static function update($checkPermissions = TRUE) {
    return (new Generic\DAOUpdateAction(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\DAODeleteAction
   */
  public static function delete($checkPermissions = TRUE) {
    return (new Generic\DAODeleteAction(__CLASS__, __FUNCTION__))
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
    // Only herald's own code writes rows, with checkPermissions off.
    $internal = [\CRM_Core_Permission::ALWAYS_DENY_PERMISSION];
    return [
      'meta' => ['administer CiviCRM'],
      'default' => ['administer CiviCRM'],
      'update' => $internal,
      'delete' => $internal,
    ];
  }

}
