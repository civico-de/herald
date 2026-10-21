<?php

declare(strict_types = 1);

use Civi\Herald\Channel\PreferredChannelOptions;

/**
 * The preference field of contacts picks from the options the channels fill.
 */
final class CRM_Herald_Upgrader_PreferredChannelField {

  /**
   * The preference field kept its inline option group; it now uses the one
   * the channels fill, created here ahead of the reconcile that adopts it.
   */
  public static function repoint(): void {
    $exists = \Civi\Api4\OptionGroup::get(checkPermissions: FALSE)
      ->addWhere('name', '=', PreferredChannelOptions::OPTION_GROUP)
      ->execute()
      ->count();
    if ($exists === 0) {
      \Civi\Api4\OptionGroup::create(checkPermissions: FALSE)
        ->addValue('name', PreferredChannelOptions::OPTION_GROUP)
        ->addValue('title', 'Herald: Preferred channel')
        ->addValue('is_reserved', TRUE)
        ->execute();
    }
    $field = \Civi\Api4\CustomField::get(checkPermissions: FALSE)
      ->addSelect('id', 'option_group_id', 'option_group_id:name')
      ->addWhere('custom_group_id:name', '=', 'herald_preferences')
      ->addWhere('name', '=', 'preferred_channel')
      ->execute()
      ->first();
    if ($field === NULL || $field['option_group_id:name'] === PreferredChannelOptions::OPTION_GROUP) {
      return;
    }
    \Civi\Api4\CustomField::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $field['id'])
      ->addValue('option_group_id:name', PreferredChannelOptions::OPTION_GROUP)
      ->execute();
    if (!empty($field['option_group_id'])) {
      \Civi\Api4\OptionGroup::delete(checkPermissions: FALSE)
        ->addWhere('id', '=', $field['option_group_id'])
        ->execute();
    }
  }

}
