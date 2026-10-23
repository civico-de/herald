<?php

declare(strict_types = 1);

use Civi\Api4\CustomField;
use Civi\Api4\UFField;
use Civi\Api4\UFGroup;
use Civi\Api4\UFJoin;
use Civi\Herald\Subscribers\Subscriptions;

/**
 * The `herald_my_preferences` profile lets staff set their group channel and
 * list, add and edit their own subscription rows at
 * /civicrm/profile/edit?gid=<id>&reset=1. Not mgd-php:
 * a UFField names its custom field as `custom_<id>`, known only once the
 * managed custom fields exist.
 */
final class CRM_Herald_Upgrader_PreferencesProfile {

  public const NAME = 'herald_my_preferences';

  private const TITLE = 'My notifications';

  /**
   * The title of the one-record profile; an admin's own title stays.
   */
  private const SINGLE_RECORD_TITLE = 'My notification preferences';

  private const HELP = 'Choose how messages to your groups reach you, and add a row for each channel you want topic notifications on.';

  /**
   * The help text of the one-record profile; an admin's own text stays.
   */
  private const SINGLE_RECORD_HELP = 'Pick your preferred channel and what kinds of notifications you want to receive.';

  /**
   * The group channel shows as a form, the rows as a list of their
   * is_multi_summary fields.
   */
  private const FIELDS = [
    [
      'group' => Subscriptions::GROUP_DELIVERY,
      'name' => 'channel',
      'label' => 'Channel for messages to my groups',
      'required' => FALSE,
      'weight' => 1,
      'help' => 'Leave it empty to receive messages to your groups by email.',
    ],
    [
      'group' => Subscriptions::GROUP_DELIVERY,
      'name' => 'recipient',
      'label' => 'Recipient for messages to my groups',
      'required' => FALSE,
      'weight' => 2,
    ],
    ['group' => Subscriptions::GROUP, 'name' => 'channel', 'label' => 'Channel', 'required' => TRUE, 'weight' => 10],
    [
      'group' => Subscriptions::GROUP,
      'name' => 'recipient',
      'label' => 'Recipient',
      'required' => FALSE,
      'weight' => 20,
    ],
    ['group' => Subscriptions::GROUP, 'name' => 'topics', 'label' => 'Topics', 'required' => TRUE, 'weight' => 30],
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
        ->addWhere('custom_group_id:name', '=', $f['group'])
        ->addWhere('name', '=', $f['name'])
        ->execute()
        ->first();
      if ($cf === NULL) {
        throw new CRM_Core_Exception("herald: custom field {$f['group']}.{$f['name']} is missing");
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
        ->addValue('is_multi_summary', $f['group'] === Subscriptions::GROUP)
        ->addValue('is_active', value: TRUE)
        ->addValue('visibility', 'User and User Admin Only')
        ->addValue('help_pre', $f['help'] ?? NULL)
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
    /** @var array{id: int, title: string, help_pre: string|null}|null $existing */
    $existing = UFGroup::get(checkPermissions: FALSE)
      ->addSelect('id', 'title', 'help_pre')
      ->addWhere('name', '=', self::NAME)
      ->execute()
      ->first();
    if ($existing !== NULL) {
      self::replaceShipped($existing['id'], 'title', $existing['title'], self::SINGLE_RECORD_TITLE, self::TITLE);
      self::replaceShipped($existing['id'], 'help_pre', $existing['help_pre'], self::SINGLE_RECORD_HELP, self::HELP);
      return $existing['id'];
    }
    /** @var array{id: int} $created */
    $created = UFGroup::create(checkPermissions: FALSE)
      ->addValue('name', self::NAME)
      ->addValue('title', self::TITLE)
      ->addValue('group_type', 'Individual,Contact')
      ->addValue('is_active', value: TRUE)
      ->addValue('is_reserved', value: FALSE)
      ->addValue('help_pre', self::HELP)
      ->execute()
      ->single();
    return $created['id'];
  }

  private static function replaceShipped(int $gid, string $field, ?string $value, string $old, string $new): void {
    if ($value === $old) {
      UFGroup::update(checkPermissions: FALSE)->addWhere('id', '=', $gid)->addValue($field, $new)->execute();
    }
  }

}
