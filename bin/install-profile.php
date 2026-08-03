<?php

/**
 * One-off: create the `herald_my_preferences` UFGroup (profile) so
 * users can self-manage their notification prefs at
 * /civicrm/profile/edit?gid=<id>&reset=1.
 *
 * Run via:
 *   cv scr /var/www/html/ext/herald/bin/install-profile.php
 *
 * Idempotent — re-running is safe; existing UFGroup/UFField rows are kept.
 *
 * Why a script instead of mgd-php: UFField references custom fields by their
 * numeric `custom_<id>`, which only exists after the custom group is
 * installed. mgd-php loads in one pass with name-based references, so we'd
 * need a second pass anyway — running this script after `cv ext:enable` is
 * the simplest "second pass".
 */

use Civi\Api4\CustomField;
use Civi\Api4\UFField;
use Civi\Api4\UFGroup;

$profileName = 'herald_my_preferences';
$profileTitle = 'My notification preferences';

$existing = UFGroup::get(FALSE)
  ->addSelect('id')
  ->addWhere('name', '=', $profileName)
  ->execute()
  ->first();

if ($existing) {
  $gid = (int) $existing['id'];
  echo "[herald] UFGroup '{$profileName}' already exists (id={$gid}).\n";
}
// @mago-expect lint:no-else-clause
else {
  $gid = (int) UFGroup::create(FALSE)
    ->addValue('name', $profileName)
    ->addValue('title', $profileTitle)
    ->addValue('group_type', 'Individual,Contact')
    ->addValue('is_active', TRUE)
    ->addValue('is_reserved', FALSE)
    ->addValue('help_pre', 'Pick your preferred channel and what kinds of notifications you want to receive.')
    ->execute()
    ->first()['id'];
  echo "[herald] Created UFGroup '{$profileName}' (id={$gid}).\n";
}

$fieldOrder = [
  ['name' => 'preferred_channel', 'label' => 'Preferred channel', 'required' => TRUE, 'weight' => 10],
  ['name' => 'telegram_chat_id', 'label' => 'Telegram chat ID', 'required' => FALSE, 'weight' => 20],
  ['name' => 'pushover_user_key', 'label' => 'Pushover user key', 'required' => FALSE, 'weight' => 30],
  ['name' => 'ntfy_topic', 'label' => 'ntfy topic', 'required' => FALSE, 'weight' => 40],
  ['name' => 'webhook_url', 'label' => 'Webhook URL', 'required' => FALSE, 'weight' => 50],
  ['name' => 'subscriptions', 'label' => 'Subscriptions', 'required' => TRUE, 'weight' => 60],
];

foreach ($fieldOrder as $f) {
  $cf = CustomField::get(FALSE)
    ->addSelect('id', 'name')
    ->addWhere('custom_group_id:name', '=', 'herald_preferences')
    ->addWhere('name', '=', $f['name'])
    ->execute()
    ->first();
  if (!$cf) {
    printf(
      "[herald] WARN: custom field 'herald_preferences.%s' missing — did you run `cv ext:enable herald` first?\n",
      $f['name'],
    );
    continue;
  }
  $fieldName = 'custom_' . $cf['id'];

  $existingField = UFField::get(FALSE)
    ->addSelect('id')
    ->addWhere('uf_group_id', '=', $gid)
    ->addWhere('field_name', '=', $fieldName)
    ->execute()
    ->first();

  if ($existingField) {
    echo "[herald]   - UFField for {$f['name']} already present (id={$existingField['id']}).\n";
    continue;
  }

  $newId = UFField::create(FALSE)
    ->addValue('uf_group_id', $gid)
    ->addValue('field_name', $fieldName)
    ->addValue('label', $f['label'])
    ->addValue('is_required', $f['required'])
    ->addValue('weight', $f['weight'])
    ->addValue('is_active', TRUE)
    ->addValue('visibility', 'User and User Admin Only')
    ->execute()
    ->first()['id'];
  echo "[herald]   + Added UFField {$f['name']} (id={$newId}).\n";
}

echo "\n[herald] Done. Self-service URL: /civicrm/profile/edit?gid={$gid}&reset=1\n";
