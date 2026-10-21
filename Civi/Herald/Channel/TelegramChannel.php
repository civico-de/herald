<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
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
    $token = Cast::toString($config['bot_token'] ?? '');
    if ($token === '') {
      throw new \CRM_Core_Exception('herald: the Telegram channel has no bot_token.');
    }
    $chatId = $config['chat_id'] ?? '';
    if (empty($chatId)) {
      throw new \CRM_Core_Exception('herald: no chat_id, neither on the delivery nor on the channel.');
    }

    $payload = TelegramPayload::of($renderedBody, $chatId, $config);
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
      // Guzzle names the request URI in its message, and the URI carries the token.
      $message = str_replace($token, '***', $e->getMessage());
      throw new \CRM_Core_Exception('herald: Telegram request failed: ' . $message);
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
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string, scope?: 'channel'|'delivery', secret?: bool, recipient?: bool, bound_to?: string}>
   */
  public function getConfigSchema(): array {
    return [
      'bot_token' => [
        'label' => E::ts('Bot token'),
        'required' => TRUE,
        'type' => 'string',
        'scope' => 'channel',
        'secret' => TRUE,
        'help' => E::ts('From @BotFather.'),
      ],
      'chat_id' => [
        'label' => E::ts('Chat ID'),
        'required' => FALSE,
        'type' => 'string',
        'recipient' => TRUE,
        'help' => E::ts('Telegram chat or group ID. Negative numbers are group chats.'),
      ],
      'parse_mode' => [
        'label' => E::ts('Parse Mode'),
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['HTML' => 'HTML', 'MarkdownV2' => 'MarkdownV2', 'text' => E::ts('Plain text')],
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
   * vocabulary ('HTML'|'MarkdownV2'|'text') rather than the semantic 'text'|'html'
   * — neither semantic maps cleanly, so neither is offered.
   *
   * @return array<string, string>
   */
  public function getSemanticFields(): array {
    return [];
  }

}
