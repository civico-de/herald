<?php

declare(strict_types = 1);

use Civi\Herald\Subscribers\LegacyPreferences;
use CRM_Herald_ExtensionUtil as E;

/**
 * The one preference record per contact of an older release, as it declared it.
 */
final class CRM_Herald_Upgrader_PreferencesGroup {

  /**
   * The old group as an older release declared it, so the reconcile ahead of
   * the upgrade keeps it. Matched by name, so an existing group is adopted.
   *
   * @return list<array<string, mixed>>
   */
  public static function declarations(): array {
    $managed = [[
      'name' => 'CustomGroup_herald_preferences',
      'entity' => 'CustomGroup',
      'cleanup' => 'unused',
      'update' => 'unmodified',
      'params' => [
        'version' => 4,
        'match' => ['name'],
        'values' => [
          'name' => LegacyPreferences::GROUP,
          'title' => E::ts('Notification preferences'),
          'extends' => 'Contact',
          'style' => 'Tab',
          'collapse_display' => FALSE,
          'is_active' => TRUE,
        ],
      ],
    ]];
    $fields = [
      ['preferred_channel', 'Preferred channel', 'String', 'Radio', 'herald_preferred_channel'],
      ['telegram_chat_id', 'Telegram chat ID', 'String', 'Text', NULL],
      ['pushover_user_key', 'Pushover user/group key', 'String', 'Text', NULL],
      ['ntfy_topic', 'ntfy topic', 'String', 'Text', NULL],
      ['webhook_url', 'Webhook URL', 'String', 'Text', NULL],
      ['subscriptions', 'Subscriptions', 'String', 'CheckBox', CRM_Herald_Upgrader_SubscriptionTypes::fieldGroup()],
      ['source_entity_type', 'Scope: entity type', 'String', 'Text', NULL],
      ['source_entity_id', 'Scope: entity ID', 'Int', 'Text', NULL],
    ];
    foreach ($fields as $index => [$name, $label, $dataType, $htmlType, $options]) {
      $values = [
        'custom_group_id.name' => LegacyPreferences::GROUP,
        'name' => $name,
        'label' => E::ts($label),
        'data_type' => $dataType,
        'html_type' => $htmlType,
        'is_searchable' => TRUE,
        'is_active' => TRUE,
        'weight' => ($index + 1) * 10,
      ];
      if ($options !== NULL) {
        $values['option_group_id.name'] = $options;
      }
      if ($name === 'subscriptions') {
        $values['serialize'] = 1;
      }
      $managed[] = [
        'name' => 'CustomField_herald_preferences_' . $name,
        'entity' => 'CustomField',
        'cleanup' => 'unused',
        'update' => 'unmodified',
        'params' => ['version' => 4, 'match' => ['custom_group_id', 'name'], 'values' => $values],
      ];
    }
    return $managed;
  }

}
