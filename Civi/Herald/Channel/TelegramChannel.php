<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use CRM_Herald_ExtensionUtil as E;
use GuzzleHttp\ClientInterface;

class TelegramChannel implements ChannelInterface {

  /**
   * sendMessage rejects anything longer with HTTP 400 "text is too long".
   * https://core.telegram.org/bots/api#sendmessage
   */
  public const MAX_TEXT_LENGTH = 4096;

  private ?ClientInterface $httpClient;

  public function __construct(?ClientInterface $httpClient = NULL) {
    $this->httpClient = $httpClient;
  }

  public function getKey(): string {
    return 'telegram';
  }

  /**
   * @param array<string, mixed> $config
   */
  public function send(string $renderedBody, array $config): void {
    $token = self::str(\Civi::settings()->get('herald_telegram_bot_token'));
    if ($token === '') {
      throw new \CRM_Core_Exception(
        'herald: Telegram bot token is not configured. Set "herald_telegram_bot_token" in CRM settings.',
      );
    }

    // !empty, not ??: the action form always stores chat_id (possibly ''),
    // and an empty value must still fall back to the default setting.
    $chatId = !empty($config['chat_id'])
      ? $config['chat_id']
      : \Civi::settings()->get('herald_telegram_default_chat_id');
    if (!$chatId) {
      throw new \CRM_Core_Exception('herald: No chat_id supplied and no default chat ID is configured.');
    }

    // '' means "Plain text" — omit the key entirely rather than sending an
    // empty parse_mode to the Bot API.
    $parseMode = self::str($config['parse_mode'] ?? 'HTML');
    $payload = [
      'chat_id' => self::str($chatId),
      'text' => TelegramText::fit(TelegramText::forParseMode($renderedBody, $parseMode), $parseMode),
    ];
    if ($parseMode !== '') {
      $payload['parse_mode'] = $parseMode;
    }
    if (isset($config['disable_notification'])) {
      $payload['disable_notification'] = (bool) $config['disable_notification'];
    }
    if (isset($config['disable_web_page_preview'])) {
      $payload['disable_web_page_preview'] = (bool) $config['disable_web_page_preview'];
    }

    $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $token);
    $client = $this->httpClient ?? new \GuzzleHttp\Client();

    try {
      $response = $client->request('POST', $url, [
        'json' => $payload,
        'http_errors' => FALSE,
        'timeout' => 10,
      ]);
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception('herald: Telegram request failed: ' . $e->getMessage(), 0, [], $e);
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    if ($status < 200 || $status >= 300) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: Telegram API returned HTTP %d. Body: %s',
        $status,
        substr($body, 0, 500),
      ));
    }

    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded) || empty($decoded['ok'])) {
      throw new \CRM_Core_Exception('herald: Telegram API responded with ok=false. Body: ' . substr($body, 0, 500));
    }
  }

  /**
   * Non-scalar config values collapse to '' instead of "Array".
   */
  private static function str(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string}>
   */
  public function getConfigSchema(): array {
    return [
      'chat_id' => [
        'label' => E::ts('Chat ID'),
        'required' => FALSE,
        'type' => 'string',
        'help' => E::ts(
          'Telegram chat or group ID. Negative numbers are group chats. If empty, the default from settings is used.',
        ),
      ],
      'parse_mode' => [
        'label' => E::ts('Parse Mode'),
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['HTML' => 'HTML', 'MarkdownV2' => 'MarkdownV2', '' => E::ts('Plain text')],
        'default' => 'HTML',
      ],
      'disable_notification' => [
        'label' => E::ts('Silent message'),
        'required' => FALSE,
        'type' => 'bool',
      ],
      'disable_web_page_preview' => [
        'label' => E::ts('Disable link previews'),
        'required' => FALSE,
        'type' => 'bool',
      ],
    ];
  }

  /**
   * Telegram messages have no subject line, and parse_mode is a Telegram
   * vocabulary ('HTML'|'MarkdownV2'|'') rather than the semantic 'text'|'html'
   * — neither semantic maps cleanly, so neither is offered.
   *
   * @return array<string, string>
   */
  public function getSemanticFields(): array {
    return [];
  }

}
