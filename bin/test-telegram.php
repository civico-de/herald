<?php

/**
 * Smoke-test the Telegram channel end-to-end.
 * Run with:  cv scr /var/www/html/ext/herald/bin/test-telegram.php
 *
 * Optional: set HERALD_TEST_MESSAGE in env to customise the body,
 * or HERALD_TEST_CHAT_ID to override the default chat.
 */

use Civi\Herald\Channel\TelegramChannel;

$body = getenv('HERALD_TEST_MESSAGE') ?: ('<b>herald smoke test</b> — sent ' . date('c'));
$chatId = getenv('HERALD_TEST_CHAT_ID') ?: NULL;

$channel = new TelegramChannel();
$channel->send($body, $chatId ? ['chat_id' => $chatId] : []);

echo "OK: message dispatched\n";
