<?php

declare(strict_types = 1);

use Civi\Herald\Subscribers\Subscriptions;
use Civi\Herald\Subscribers\SubscriptionScope;
use CRM_Herald_ExtensionUtil as E;

/**
 * Per-contact subscriptions shipped via mgd-php@1.
 *
 * Defines:
 *   - Option groups `herald_preferred_channel` and `herald_subscribable_topic`,
 *     which herald fills from its channels and topics, and `herald_scope_entity`.
 *   - Multi-record custom group `herald_subscription` on Contact: one row per
 *     channel, recipient, topics and optional scope.
 *   - Custom group `herald_group_delivery` on Contact: the channel and
 *     recipient for messages to the contact's groups.
 *
 * Both groups are tabs so the summary stays clear; the "Notifications" forms
 * replace those tabs (NotificationsForm).
 */

/**
 * @var array<int, array{name: string, label: string, data_type: string, html_type: string, weight: int, help_pre?: string, option_group_name?: string, serialize?: int, is_required?: bool}> $subscriptionFields
 */
$subscriptionFields = [
  [
    'name' => 'channel',
    'label' => E::ts('Channel'),
    'data_type' => 'String',
    'html_type' => 'Select',
    // Values are the subscribable channels (PreferredChannelOptions::sync()).
    'option_group_name' => 'herald_preferred_channel',
    'is_required' => TRUE,
    'weight' => 10,
  ],
  [
    'name' => 'recipient',
    'label' => E::ts('Recipient'),
    'data_type' => 'String',
    'html_type' => 'Text',
    'weight' => 20,
    'help_pre' => E::ts(
      'Where the channel delivers to: a Telegram chat ID, a Pushover user or group key, an ntfy topic or a webhook URL. Leave it empty for an email channel to use your primary address.',
    ),
  ],
  [
    'name' => 'topics',
    'label' => E::ts('Topics'),
    'data_type' => 'String',
    'html_type' => 'CheckBox',
    // Values are the subscribable topics (SubscribableTopics::sync()).
    'option_group_name' => 'herald_subscribable_topic',
    'serialize' => 1,
    'weight' => 30,
    'help_pre' => E::ts('The topics you want to receive notifications about through this channel.'),
  ],
  [
    'name' => 'source_entity_type',
    'label' => E::ts('Scope: entity type'),
    'data_type' => 'String',
    'html_type' => 'Select',
    'option_group_name' => SubscriptionScope::OPTION_GROUP,
    'weight' => 50,
    'help_pre' => E::ts(
      'Optional: limit this subscription to one record, such as an event. Leave empty for a global subscription.',
    ),
  ],
  [
    'name' => 'source_entity_id',
    'label' => E::ts('Scope: entity ID'),
    'data_type' => 'Int',
    'html_type' => 'Text',
    'weight' => 60,
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
  'name' => 'OptionGroup_herald_scope_entity',
  'entity' => 'OptionGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'values' => [
      'name' => SubscriptionScope::OPTION_GROUP,
      'title' => E::ts('Herald: Scope entity'),
      'description' => E::ts('The records a subscription can be limited to.'),
      'is_active' => TRUE,
      'is_reserved' => TRUE,
    ],
  ],
];
foreach (array_keys(SubscriptionScope::entities()) as $weight => $entity) {
  $managed[] = [
    'name' => 'OptionValue_herald_scope_entity_' . $entity,
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => SubscriptionScope::OPTION_GROUP,
        'name' => $entity,
        'value' => $entity,
        'label' => SubscriptionScope::entities()[$entity],
        'weight' => $weight + 1,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
      ],
    ],
  ];
}

// Until upgrade_1017 has moved the preferences into subscriptions, they stay as shipped.
if (\Civi\Herald\Subscribers\LegacyPreferences::pending()) {
  $managed = [...$managed, ...CRM_Herald_Upgrader_PreferencesGroup::declarations()];
}

$managed[] = [
  'name' => 'CustomGroup_herald_subscription',
  'entity' => 'CustomGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'values' => [
      'name' => 'herald_subscription',
      'title' => E::ts('Notification subscriptions'),
      'extends' => 'Contact',
      'style' => 'Tab',
      'is_multiple' => TRUE,
      'collapse_display' => FALSE,
      'is_active' => TRUE,
      'help_pre' => E::ts('The channels this contact receives herald notifications through, and on which topics.'),
    ],
  ],
];

$managed[] = [
  'name' => 'CustomGroup_herald_group_delivery',
  'entity' => 'CustomGroup',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'values' => [
      'name' => Subscriptions::GROUP_DELIVERY,
      'title' => E::ts('Messages to groups'),
      'extends' => 'Contact',
      'style' => 'Tab',
      'is_multiple' => FALSE,
      'collapse_display' => FALSE,
      'is_active' => TRUE,
      'help_pre' => E::ts(
        'How messages sent to a group this contact belongs to reach them. Without a channel they go by email to the primary address.',
      ),
    ],
  ],
];

/**
 * @var array<int, array{name: string, label: string, data_type: string, html_type: string, weight: int, help_pre?: string, option_group_name?: string, serialize?: int, is_required?: bool}> $groupDeliveryFields
 */
$groupDeliveryFields = [
  [
    'name' => 'channel',
    'label' => E::ts('Channel'),
    'data_type' => 'String',
    'html_type' => 'Select',
    'option_group_name' => 'herald_preferred_channel',
    'weight' => 10,
    'help_pre' => E::ts('Leave it empty to receive messages to your groups by email.'),
  ],
  [
    'name' => 'recipient',
    'label' => E::ts('Recipient'),
    'data_type' => 'String',
    'html_type' => 'Text',
    'weight' => 20,
    'help_pre' => E::ts(
      'Where the channel delivers to: a Telegram chat ID, a Pushover user or group key, an ntfy topic or a webhook URL. Leave it empty for an email channel to use your primary address.',
    ),
  ],
];

foreach ([
  Subscriptions::GROUP => $subscriptionFields,
  Subscriptions::GROUP_DELIVERY => $groupDeliveryFields,
] as $group => $fields) {
  foreach ($fields as $f) {
    $values = [
      'custom_group_id.name' => $group,
      'name' => $f['name'],
      'label' => $f['label'],
      'data_type' => $f['data_type'],
      'html_type' => $f['html_type'],
      'is_searchable' => TRUE,
      'is_active' => TRUE,
      'is_required' => $f['is_required'] ?? FALSE,
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
      'name' => "CustomField_{$group}_{$f['name']}",
      'entity' => 'CustomField',
      'cleanup' => 'unused',
      'update' => 'unmodified',
      'params' => ['version' => 4, 'values' => $values],
    ];
  }
}

return $managed;
