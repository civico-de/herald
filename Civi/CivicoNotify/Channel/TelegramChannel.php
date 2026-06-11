<?php

namespace Civi\CivicoNotify\Channel;

use GuzzleHttp\ClientInterface;

class TelegramChannel implements ChannelInterface {

  private ?ClientInterface $httpClient;

  public function __construct(?ClientInterface $httpClient = NULL) {
    $this->httpClient = $httpClient;
  }

  public function getKey(): string {
    return 'telegram';
  }

  public function send(string $renderedBody, array $config): void {
    $token = (string) \Civi::settings()->get('civico_notify_telegram_bot_token');
    if ($token === '') {
      throw new \CRM_Core_Exception('civico_notify: Telegram bot token is not configured. Set "civico_notify_telegram_bot_token" in CRM settings.');
    }

    // !empty, not ??: the action form always stores chat_id (possibly ''),
    // and an empty value must still fall back to the default setting.
    $chatId = !empty($config['chat_id'])
      ? $config['chat_id']
      : \Civi::settings()->get('civico_notify_telegram_default_chat_id');
    if (!$chatId) {
      throw new \CRM_Core_Exception('civico_notify: No chat_id supplied and no default chat ID is configured.');
    }

    $payload = [
      'chat_id' => (string) $chatId,
      'text' => $renderedBody,
    ];
    // '' means "Plain text" — omit the key entirely rather than sending an
    // empty parse_mode to the Bot API.
    $parseMode = (string) ($config['parse_mode'] ?? 'HTML');
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
      throw new \CRM_Core_Exception('civico_notify: Telegram request failed: ' . $e->getMessage(), 0, [], $e);
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    if ($status < 200 || $status >= 300) {
      throw new \CRM_Core_Exception(sprintf(
        'civico_notify: Telegram API returned HTTP %d. Body: %s',
        $status,
        substr($body, 0, 500)
      ));
    }

    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded) || empty($decoded['ok'])) {
      throw new \CRM_Core_Exception('civico_notify: Telegram API responded with ok=false. Body: ' . substr($body, 0, 500));
    }
  }

  public function getConfigSchema(): array {
    return [
      'chat_id' => [
        'label' => ts('Chat ID'),
        'required' => FALSE,
        'type' => 'string',
        'help' => ts('Telegram chat or group ID. Negative numbers are group chats. If empty, the default from settings is used.'),
      ],
      'parse_mode' => [
        'label' => ts('Parse Mode'),
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['HTML' => 'HTML', 'MarkdownV2' => 'MarkdownV2', '' => ts('Plain text')],
        'default' => 'HTML',
      ],
      'disable_notification' => [
        'label' => ts('Silent message'),
        'required' => FALSE,
        'type' => 'bool',
      ],
      'disable_web_page_preview' => [
        'label' => ts('Disable link previews'),
        'required' => FALSE,
        'type' => 'bool',
      ],
    ];
  }

}
