<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The JSON body of a Bot API sendMessage call: chat, fitted text, parse mode
 * and the two switches, each only when the delivery sets it.
 */
final class TelegramPayload {

  /**
   * @param array<string, mixed> $config
   * @return array<string, string|bool>
   */
  public static function of(string $renderedBody, mixed $chatId, array $config): array {
    // "text" is plain text: the Bot API gets no parse_mode at all. An empty
    // value is unset, so a channel default or HTML applies.
    $parseMode = Cast::toString($config['parse_mode'] ?? '' ?: 'HTML');
    $parseMode = $parseMode === 'text' ? '' : $parseMode;
    $payload = [
      'chat_id' => Cast::toString($chatId),
      'text' => TelegramText::fit(TelegramText::forParseMode($renderedBody, $parseMode), $parseMode),
    ];
    if ($parseMode !== '') {
      $payload['parse_mode'] = $parseMode;
    }
    if (isset($config['disable_notification'])) {
      $payload['disable_notification'] = !empty($config['disable_notification']);
    }
    if (isset($config['disable_web_page_preview'])) {
      $payload['disable_web_page_preview'] = !empty($config['disable_web_page_preview']);
    }
    return $payload;
  }

}
