<?php

/**
 * Smoke-test the Telegram channel end-to-end.
 * Run with:  cv scr /var/www/html/ext/civico_notify/bin/test-telegram.php
 *
 * Optional: set CIVICO_NOTIFY_TEST_MESSAGE in env to customise the body,
 * or CIVICO_NOTIFY_TEST_CHAT_ID to override the default chat.
 */

use Civi\CivicoNotify\Channel\TelegramChannel;

$body = getenv('CIVICO_NOTIFY_TEST_MESSAGE') ?: ('<b>civico_notify smoke test</b> — sent ' . date('c'));
$chatId = getenv('CIVICO_NOTIFY_TEST_CHAT_ID') ?: NULL;

$channel = new TelegramChannel();
$channel->send($body, $chatId ? ['chat_id' => $chatId] : []);

echo "OK: message dispatched\n";
