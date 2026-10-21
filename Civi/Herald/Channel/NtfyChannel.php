<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;
use GuzzleHttp\ClientInterface;

/**
 * ntfy.sh channel — POSTs the rendered body to a topic.
 *
 * Works against ntfy.sh (default) or any self-hosted ntfy server. The body of
 * the POST is the rendered message as plain text (PlainText); metadata (title, priority, tags, click,
 * markdown) is passed via `X-*` headers so the body stays human-readable.
 *
 * Config:
 *   ntfy_server    string  Base URL (default https://ntfy.sh)
 *   ntfy_token     string  Bearer token for protected topics
 *   ntfy_topic     string  Topic to publish to (required)
 *   ntfy_title     string  X-Title header
 *   ntfy_priority  int|str X-Priority (1=min … 5=max, or string aliases)
 *   ntfy_tags      string  X-Tags (comma-separated)
 *   ntfy_click     string  X-Click URL opened when notification is tapped
 *   ntfy_markdown  bool    X-Markdown: yes when truthy
 */
class NtfyChannel implements ChannelInterface {

  private ?ClientInterface $httpClient;

  public function __construct(?ClientInterface $httpClient = NULL) {
    $this->httpClient = $httpClient;
  }

  public function getKey(): string {
    return 'ntfy';
  }

  /**
   * @param array<string, mixed> $config
   */
  public function send(string $renderedBody, array $config): void {
    $topic = $config['ntfy_topic'] ?? '';
    if (empty($topic)) {
      throw new \CRM_Core_Exception('herald: no ntfy_topic, neither on the delivery nor on the channel.');
    }

    $server = Cast::toString($config['ntfy_server'] ?? '' ?: 'https://ntfy.sh');
    $url = rtrim($server, '/') . '/' . ltrim(Cast::toString($topic), '/');

    $headers = self::buildHeaders($config);
    $token = Cast::toString($config['ntfy_token'] ?? '');
    if ($token !== '') {
      $headers['Authorization'] = 'Bearer ' . $token;
    }

    $client = $this->httpClient ?? new \GuzzleHttp\Client();
    try {
      $response = $client->request('POST', $url, [
        'body' => PlainText::fromHtml($renderedBody),
        'headers' => $headers,
        'http_errors' => FALSE,
        'timeout' => 10,
      ]);
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception('herald: ntfy request failed: ' . $e->getMessage(), 0, [], $e);
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: ntfy returned HTTP %d. Body: %s',
        $status,
        substr((string) $response->getBody(), 0, 500),
      ));
    }
  }

  /**
   * Build the X-* metadata headers from action config. Pure — test-seam.
   *
   * @param array<string, mixed> $config
   * @return array<string, string>
   */
  public static function buildHeaders(array $config): array {
    return NtfyHeaders::of($config);
  }

  /**
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string, scope?: 'channel'|'delivery', secret?: bool, recipient?: bool, bound_to?: string}>
   */
  public function getConfigSchema(): array {
    return [
      'ntfy_server' => [
        'label' => E::ts('Server'),
        'required' => FALSE,
        'type' => 'string',
        'scope' => 'channel',
        'default' => 'https://ntfy.sh',
      ],
      'ntfy_token' => [
        'label' => E::ts('Access token'),
        'required' => FALSE,
        'type' => 'string',
        'scope' => 'channel',
        'secret' => TRUE,
        'help' => E::ts('Only needed for protected topics.'),
      ],
      'ntfy_topic' => ['label' => E::ts('Topic'), 'required' => TRUE, 'type' => 'string', 'recipient' => TRUE],
      'ntfy_title' => ['label' => E::ts('Title'), 'required' => FALSE, 'type' => 'string'],
      'ntfy_priority' => ['label' => E::ts('Priority (1-5)'), 'required' => FALSE, 'type' => 'int'],
      'ntfy_tags' => ['label' => E::ts('Tags (comma-separated)'), 'required' => FALSE, 'type' => 'string'],
      'ntfy_click' => ['label' => E::ts('Click URL'), 'required' => FALSE, 'type' => 'string'],
      'ntfy_markdown' => ['label' => E::ts('Render body as Markdown'), 'required' => FALSE, 'type' => 'bool'],
    ];
  }

  /**
   * No 'format': the ntfy body is always plain text (markdown is opt-in via
   * ntfy_markdown, which is not the same 'text'|'html' axis).
   *
   * @return array<string, string>
   */
  public function getSemanticFields(): array {
    return ['subject' => 'ntfy_title'];
  }

}
