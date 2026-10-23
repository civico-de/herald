<?php

declare(strict_types = 1);

use Civi\Api4\CustomField;
use Civi\Api4\UFField;
use Civi\Api4\UFGroup;
use Civi\Api4\UFJoin;
use Civi\Herald\Subscribers\Subscriptions;

/**
 * The `herald_my_preferences` profile lets staff list, add and edit their own
 * subscription rows at /civicrm/profile/edit?gid=<id>&reset=1. Not mgd-php:
 * a UFField names its custom field as `custom_<id>`, known only once the
 * managed custom fields exist.
 */
final class CRM_Herald_Upgrader_PreferencesProfile {

  public const NAME = 'herald_my_preferences';

  private const HELP = 'Add a row for each channel you want notifications on, with the topics it carries.';

  /**
   * The help text of the one-record profile; an admin's own text stays.
   */
  private const SINGLE_RECORD_HELP = 'Pick your preferred channel and what kinds of notifications you want to receive.';

  /**
   * The row list shows only fields with is_multi_summary.
   */
  private const FIELDS = [
    ['name' => 'channel', 'label' => 'Channel', 'required' => TRUE, 'weight' => 10],
    ['name' => 'recipient', 'label' => 'Recipient', 'required' => FALSE, 'weight' => 20],
    ['name' => 'topics', 'label' => 'Topics', 'required' => FALSE, 'weight' => 30],
    ['name' => 'for_groups', 'label' => 'Group messages', 'required' => FALSE, 'weight' => 40],
  ];

  /**
   * Create the profile, its standalone-form use and any missing field;
   * existing rows, including admin edits to them, are kept.
   *
   * @return int The profile's UFGroup id.
   */
  public static function ensure(): int {
    $gid = self::group();
    self::standalone($gid);
    foreach (self::FIELDS as $f) {
      /** @var array{id: int}|null $cf */
      $cf = CustomField::get(checkPermissions: FALSE)
        ->addSelect('id')
        ->addWhere('custom_group_id:name', '=', Subscriptions::GROUP)
        ->addWhere('name', '=', $f['name'])
        ->execute()
        ->first();
      if ($cf === NULL) {
        throw new CRM_Core_Exception('herald: custom field ' . Subscriptions::GROUP . ".{$f['name']} is missing");
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
        ->addValue('is_multi_summary', value: TRUE)
        ->addValue('is_active', value: TRUE)
        ->addValue('visibility', 'User and User Admin Only')
        ->execute();
    }
    return $gid;
  }

  /**
   * core's profile/edit refuses a profile without a `Profile` join.
   */
  private static function standalone(int $gid): void {
    $joined = UFJoin::get(checkPermissions: FALSE)
      ->addWhere('uf_group_id', '=', $gid)
      ->addWhere('module', '=', 'Profile')
      ->execute()
      ->count();
    if ($joined > 0) {
      return;
    }
    UFJoin::create(checkPermissions: FALSE)
      ->addValue('uf_group_id', $gid)
      ->addValue('module', 'Profile')
      ->addValue('is_active', value: TRUE)
      ->addValue('weight', 1)
      ->execute();
  }

  private static function group(): int {
    /** @var array{id: int, help_pre: string|null}|null $existing */
    $existing = UFGroup::get(checkPermissions: FALSE)
      ->addSelect('id', 'help_pre')
      ->addWhere('name', '=', self::NAME)
      ->execute()
      ->first();
    if ($existing !== NULL) {
      if ($existing['help_pre'] === self::SINGLE_RECORD_HELP) {
        UFGroup::update(checkPermissions: FALSE)
          ->addWhere('id', '=', $existing['id'])
          ->addValue('help_pre', self::HELP)
          ->execute();
      }
      return $existing['id'];
    }
    /** @var array{id: int} $created */
    $created = UFGroup::create(checkPermissions: FALSE)
      ->addValue('name', self::NAME)
      ->addValue('title', 'My notification preferences')
      ->addValue('group_type', 'Individual,Contact')
      ->addValue('is_active', value: TRUE)
      ->addValue('is_reserved', value: FALSE)
      ->addValue('help_pre', self::HELP)
      ->execute()
      ->single();
    return $created['id'];
  }

}
