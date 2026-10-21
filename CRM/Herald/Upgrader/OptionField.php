<?php

declare(strict_types = 1);

/**
 * A preference field of contacts picks from an option group herald fills.
 */
final class CRM_Herald_Upgrader_OptionField {

  /**
   * The reconcile moves a field nobody changed, help text included; an
   * admin's own field is moved here.
   *
   * @param callable(): void $fill Fills the group
   */
  public static function move(string $field, string $group, string $title, callable $fill): void {
    self::ensureGroup($group, $title);
    $fill();
    CRM_Herald_Upgrader_Reconcile::run();
    self::repoint($field, $group, $title);
  }

  /**
   * Created here ahead of the reconcile that adopts it.
   */
  public static function ensureGroup(string $group, string $title): void {
    $exists = \Civi\Api4\OptionGroup::get(checkPermissions: FALSE)
      ->addWhere('name', '=', $group)
      ->execute()
      ->count();
    if ($exists > 0) {
      return;
    }
    \Civi\Api4\OptionGroup::create(checkPermissions: FALSE)
      ->addValue('name', $group)
      ->addValue('title', $title)
      ->addValue('is_reserved', TRUE)
      ->execute();
  }

  /**
   * The field leaves its former option group; core deletes that group when no
   * other field uses it. The stored values stay.
   */
  public static function repoint(string $field, string $group, string $title): void {
    self::ensureGroup($group, $title);
    $record = \Civi\Api4\CustomField::get(checkPermissions: FALSE)
      ->addSelect('id', 'option_group_id:name')
      ->addWhere('custom_group_id:name', '=', 'herald_preferences')
      ->addWhere('name', '=', $field)
      ->execute()
      ->first();
    if ($record === NULL || $record['option_group_id:name'] === $group) {
      return;
    }
    \Civi\Api4\CustomField::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $record['id'])
      ->addValue('option_group_id:name', $group)
      ->execute();
  }

}
