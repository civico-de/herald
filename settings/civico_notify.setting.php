<?php

use CRM_CivicoNotify_ExtensionUtil as E;

return [
  'civico_notify_telegram_bot_token' => [
    'group_name' => 'CiviCo Notify Settings',
    'group' => 'civico_notify',
    'name' => 'civico_notify_telegram_bot_token',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'add' => '5.81',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Telegram Bot API token (from @BotFather).'),
    'help_text' => E::ts('Stored encrypted at rest if Civi key is configured.'),
    'title' => E::ts('Telegram Bot Token'),
    'settings_pages' => ['civico_notify' => ['weight' => 10]],
  ],
  'civico_notify_telegram_default_chat_id' => [
    'group_name' => 'CiviCo Notify Settings',
    'group' => 'civico_notify',
    'name' => 'civico_notify_telegram_default_chat_id',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'add' => '5.81',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Fallback chat/group ID used when an action does not specify one. Negative numbers are group chats.'),
    'title' => E::ts('Default Telegram Chat ID'),
    'settings_pages' => ['civico_notify' => ['weight' => 20]],
  ],
];
