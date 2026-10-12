<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Per-contact notification preferences shipped via mgd-php@1.
 *
 * Defines:
 *   - Option group `herald_subscription_type` listing the supported
 *     subscription kinds (completed alerts, daily digests, …) so they're
 *     queryable, translatable, and admin-extensible.
 *   - Custom group `herald_preferences` on Contact with the per-user
 *     channel pick + channel-specific recipient identifiers + the multi-value
 *     `subscriptions` field.
 *
 * The self-service profile is not shipped here: its UFFields name custom
 * fields by id (CRM_Herald_Upgrader_PreferencesProfile).
 */

$subscriptionTypes = [
  ['name' => 'completed_alert', 'label' => E::ts('Every completed contribution'), 'weight' => 10],
  ['name' => 'pending_alert', 'label' => E::ts('Every pending contribution'), 'weight' => 20],
  ['name' => 'status_changed_alert', 'label' => E::ts('Pending → Completed transitions'), 'weight' => 30],
  ['name' => 'daily_digest', 'label' => E::ts('Daily digest'), 'weight' => 40],
  ['name' => 'weekly_digest', 'label' => E::ts('Weekly digest'), 'weight' => 50],
];

/**
 * @var array<int, array{name: string, label: string, data_type: string, html_type: string, weight: int, help_pre?: string, options?: array<string, string>, option_group_name?: string, serialize?: int}> $preferenceFields
 */
$preferenceFields = [
  [
    'name' => 'preferred_channel',
    'label' => E::ts('Preferred channel'),
    'data_type' => 'String',
    'html_type' => 'Radio',
    'options' => [
      'telegram' => E::ts('Telegram'),
      'webhook' => E::ts('Webhook (Slack/Discord/Mattermost/custom)'),
      'ntfy' => E::ts('ntfy.sh'),
      'pushover' => E::ts('Pushover'),
      'email' => E::ts('Email (your primary address)'),
    ],
    'help_pre' => E::ts(
      'Pick the channel where you want to receive notifications. Then fill in the recipient identifier for that channel below. Email needs no identifier — it goes to your primary address.',
    ),
    'weight' => 10,
  ],
  [
    'name' => 'telegram_chat_id',
    'label' => E::ts('Telegram chat ID'),
    'data_type' => 'String',
    'html_type' => 'Text',
    'weight' => 20,
    'help_pre' => E::ts(
      'Your personal Telegram chat ID with the bot. Talk to @userinfobot or send /start to the configured bot to find it.',
    ),
  ],
  [
    'name' => 'pushover_user_key',
    'label' => E::ts('Pushover user/group key'),
    'data_type' => 'String',
    'html_type' => 'Text',
    'weight' => 30,
  ],
  [
    'name' => 'ntfy_topic',
    'label' => E::ts('ntfy topic'),
    'data_type' => 'String',
    'html_type' => 'Text',
    'weight' => 40,
  ],
  [
    'name' => 'webhook_url',
    'label' => E::ts('Webhook URL'),
    'data_type' => 'String',
    'html_type' => 'Text',
    'weight' => 50,
    'help_pre' => E::ts('Your personal incoming-webhook URL (e.g. a Slack DM webhook).'),
  ],
  [
    'name' => 'subscriptions',
    'label' => E::ts('Subscriptions'),
    'data_type' => 'String',
    'html_type' => 'CheckBox',
    'option_group_name' => 'herald_subscription_type',
    'serialize' => 1,
    'weight' => 60,
    'help_pre' => E::ts('Tick the kinds of notifications you want to receive on your preferred channel.'),
  ],
  [
    'name' => 'source_entity_type',
    'label' => E::ts('Scope: entity type'),
    'data_type' => 'String',
    'html_type' => 'Text',
    'weight' => 70,
    'help_pre' => E::ts(
      'Optional: limit these subscriptions to one source entity (e.g. Event). Leave empty for global subscriptions.',
    ),
  ],
  [
    'name' => 'source_entity_id',
    'label' => E::ts('Scope: entity ID'),
    'data_type' => 'Int',
    'html_type' => 'Text',
    'weight' => 80,
    'help_pre' => E::ts('Optional: the ID of the source entity the scope refers to.'),
  ],
];

$managed = [];

$managed[] = [
  'name' => 'OptionGroup_herald_subscription_type',
  'entity' => 'OptionGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'values' => [
      'name' => 'herald_subscription_type',
      'title' => E::ts('Herald: Subscription type'),
      'description' => E::ts('Kinds of notification a contact can opt into. Used by herald subscriber-mode actions.'),
      'is_active' => TRUE,
      'is_reserved' => FALSE,
    ],
  ],
];

foreach ($subscriptionTypes as $st) {
  $managed[] = [
    'name' => 'OptionValue_herald_subscription_type_' . $st['name'],
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'herald_subscription_type',
        'name' => $st['name'],
        'value' => $st['name'],
        'label' => $st['label'],
        'weight' => $st['weight'],
        'is_active' => TRUE,
      ],
    ],
  ];
}

$managed[] = [
  'name' => 'CustomGroup_herald_preferences',
  'entity' => 'CustomGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'values' => [
      'name' => 'herald_preferences',
      'title' => E::ts('Notification preferences'),
      'extends' => 'Contact',
      'style' => 'Inline',
      'collapse_display' => FALSE,
      'is_active' => TRUE,
      'help_pre' => E::ts('How and when this contact wants to receive herald alerts and digests.'),
    ],
  ],
];

foreach ($preferenceFields as $f) {
  $values = [
    'custom_group_id.name' => 'herald_preferences',
    'name' => $f['name'],
    'label' => $f['label'],
    'data_type' => $f['data_type'],
    'html_type' => $f['html_type'],
    'is_searchable' => TRUE,
    'is_active' => TRUE,
    'weight' => $f['weight'],
  ];
  if (!empty($f['help_pre'])) {
    $values['help_pre'] = $f['help_pre'];
  }
  if (!empty($f['option_group_name'])) {
    $values['option_group_id.name'] = $f['option_group_name'];
  }
  if (!empty($f['options']) && empty($f['option_group_name'])) {
    // Inline option list — APIv4 accepts an assoc value=>label dict here.
    $values['option_values'] = $f['options'];
  }
  if (!empty($f['serialize'])) {
    $values['serialize'] = (int) $f['serialize'];
  }
  $managed[] = [
    'name' => 'CustomField_herald_preferences_' . $f['name'],
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => ['version' => 4, 'values' => $values],
  ];
}

return $managed;
