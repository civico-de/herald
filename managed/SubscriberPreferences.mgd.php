<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Per-contact notification preferences shipped via mgd-php@1.
 *
 * Defines:
 *   - Option groups `herald_preferred_channel` and `herald_subscribable_topic`,
 *     which herald fills from its channels and topics.
 *   - Custom group `herald_preferences` on Contact with the per-user
 *     channel pick + channel-specific recipient identifiers + the multi-value
 *     `subscriptions` field.
 *
 * The self-service profile is not shipped here: its UFFields name custom
 * fields by id (CRM_Herald_Upgrader_PreferencesProfile).
 */

/**
 * @var array<int, array{name: string, label: string, data_type: string, html_type: string, weight: int, help_pre?: string, option_group_name?: string, serialize?: int}> $preferenceFields
 */
$preferenceFields = [
  [
    'name' => 'preferred_channel',
    'label' => E::ts('Preferred channel'),
    'data_type' => 'String',
    'html_type' => 'Radio',
    // Values are the subscribable channels (PreferredChannelOptions::sync()).
    'option_group_name' => 'herald_preferred_channel',
    'help_pre' => E::ts(
      'Pick the channel where you want to receive notifications. Then fill in the recipient identifier for that channel below. An email channel needs no identifier — it goes to your primary address.',
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
    // Values are the subscribable topics (SubscribableTopics::sync()).
    'option_group_name' => CRM_Herald_Upgrader_SubscriptionTypes::fieldGroup(),
    'serialize' => 1,
    'weight' => 60,
    'help_pre' => E::ts('Tick the topics you want to receive notifications about on your preferred channel.'),
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

// Until upgrade_1015 has read the subscription types, they stay as shipped.
if (CRM_Herald_Upgrader_SubscriptionTypes::fieldGroup() !== 'herald_subscribable_topic') {
  $managed = CRM_Herald_Upgrader_SubscriptionTypes::declarations();
}

$managed[] = [
  'name' => 'OptionGroup_herald_subscribable_topic',
  'entity' => 'OptionGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    // The upgrade to subscriptions on topics creates it before it repoints the field.
    'match' => ['name'],
    'values' => [
      'name' => 'herald_subscribable_topic',
      'title' => E::ts('Herald: Subscribable topic'),
      'description' => E::ts(
        'The topics a contact can subscribe to; herald keeps them in step with its topics.',
      ),
      'is_active' => TRUE,
      'is_reserved' => TRUE,
    ],
  ],
];

$managed[] = [
  'name' => 'OptionGroup_herald_preferred_channel',
  'entity' => 'OptionGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    // The upgrade to named channels creates it before the reconcile.
    'match' => ['name'],
    'values' => [
      'name' => 'herald_preferred_channel',
      'title' => E::ts('Herald: Preferred channel'),
      'description' => E::ts(
        'The channels a contact can receive notifications through; herald keeps them in step with its channels.',
      ),
      'is_active' => TRUE,
      'is_reserved' => TRUE,
    ],
  ],
];

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
