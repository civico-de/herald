<?php

declare(strict_types = 1);

use Civi\Api4\CustomField;
use Civi\Api4\UFField;
use Civi\Api4\UFGroup;

/**
 * The `herald_my_preferences` profile lets staff edit their own
 * herald_preferences at /civicrm/profile/edit?gid=<id>&reset=1. Not mgd-php:
 * a UFField names its custom field as `custom_<id>`, known only once the
 * managed custom fields exist.
 */
final class CRM_Herald_Upgrader_PreferencesProfile {

  public const NAME = 'herald_my_preferences';

  private const FIELDS = [
    ['name' => 'preferred_channel', 'label' => 'Preferred channel', 'required' => TRUE, 'weight' => 10],
    ['name' => 'telegram_chat_id', 'label' => 'Telegram chat ID', 'required' => FALSE, 'weight' => 20],
    ['name' => 'pushover_user_key', 'label' => 'Pushover user key', 'required' => FALSE, 'weight' => 30],
    ['name' => 'ntfy_topic', 'label' => 'ntfy topic', 'required' => FALSE, 'weight' => 40],
    ['name' => 'webhook_url', 'label' => 'Webhook URL', 'required' => FALSE, 'weight' => 50],
    ['name' => 'subscriptions', 'label' => 'Subscriptions', 'required' => TRUE, 'weight' => 60],
  ];

  /**
   * Create the profile and any missing field; existing rows, including admin
   * edits to them, are kept.
   *
   * @return int The profile's UFGroup id.
   */
  public static function ensure(): int {
    $gid = self::group();
    foreach (self::FIELDS as $f) {
      /** @var array{id: int}|null $cf */
      $cf = CustomField::get(checkPermissions: FALSE)
        ->addSelect('id')
        ->addWhere('custom_group_id:name', '=', 'herald_preferences')
        ->addWhere('name', '=', $f['name'])
        ->execute()
        ->first();
      if ($cf === NULL) {
        throw new CRM_Core_Exception("herald: custom field herald_preferences.{$f['name']} is missing");
      }
      $fieldName = 'custom_' . $cf['id'];
      $present = UFField::get(checkPermissions: FALSE)
        ->addWhere('uf_group_id', '=', $gid)
        ->addWhere('field_name', '=', $fieldName)
        ->execute()
        ->count();
      if ($present > 0) {
        continue;
      }
      UFField::create(checkPermissions: FALSE)
        ->addValue('uf_group_id', $gid)
        ->addValue('field_name', $fieldName)
        ->addValue('label', $f['label'])
        ->addValue('is_required', $f['required'])
        ->addValue('weight', $f['weight'])
        ->addValue('is_active', value: TRUE)
        ->addValue('visibility', 'User and User Admin Only')
        ->execute();
    }
    return $gid;
  }

  private static function group(): int {
    /** @var array{id: int}|null $existing */
    $existing = UFGroup::get(checkPermissions: FALSE)
      ->addSelect('id')
      ->addWhere('name', '=', self::NAME)
      ->execute()
      ->first();
    if ($existing !== NULL) {
      return $existing['id'];
    }
    /** @var array{id: int} $created */
    $created = UFGroup::create(checkPermissions: FALSE)
      ->addValue('name', self::NAME)
      ->addValue('title', 'My notification preferences')
      ->addValue('group_type', 'Individual,Contact')
      ->addValue('is_active', value: TRUE)
      ->addValue('is_reserved', value: FALSE)
      ->addValue('help_pre', 'Pick your preferred channel and what kinds of notifications you want to receive.')
      ->execute()
      ->single();
    return $created['id'];
  }

}
