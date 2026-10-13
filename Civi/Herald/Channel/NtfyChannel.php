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
 * Per-call config (from action params / channel_config JSON):
 *   ntfy_topic     string  Topic to publish to (required; falls back to setting)
 *   ntfy_title     string  X-Title header
 *   ntfy_priority  int|str X-Priority (1=min … 5=max, or string aliases)
 *   ntfy_tags      string  X-Tags (comma-separated)
 *   ntfy_click     string  X-Click URL opened when notification is tapped
 *   ntfy_markdown  bool    X-Markdown: yes when truthy
 *
 * Settings:
 *   herald_ntfy_server         — base URL (default https://ntfy.sh)
 *   herald_ntfy_default_topic  — fallback topic
 *   herald_ntfy_token          — Bearer token for protected topics
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
    // !empty, not ??: an empty ntfy_topic must still fall back to the setting.
    $topic = !empty($config['ntfy_topic'])
      ? $config['ntfy_topic']
      : \Civi::settings()->get('herald_ntfy_default_topic');
    if (!$topic) {
      throw new \CRM_Core_Exception(
        'herald: ntfy channel requires ntfy_topic (action param or herald_ntfy_default_topic setting).',
      );
    }

    $server = Cast::toString(\Civi::settings()->get('herald_ntfy_server') ?: 'https://ntfy.sh');
    $url = rtrim($server, '/') . '/' . ltrim(Cast::toString($topic), '/');

    $headers = self::buildHeaders($config);
    $token = Cast::toString(\Civi::settings()->get('herald_ntfy_token'));
    if ($token !== '' && !isset($headers['Authorization'])) {
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
    $h = ['Content-Type' => 'text/plain; charset=utf-8'];
    if (!empty($config['ntfy_title'])) {
      $h['X-Title'] = Cast::toString($config['ntfy_title']);
    }
    if (isset($config['ntfy_priority']) && $config['ntfy_priority'] !== '') {
      $h['X-Priority'] = Cast::toString($config['ntfy_priority']);
    }
    if (!empty($config['ntfy_tags'])) {
      $h['X-Tags'] = Cast::toString($config['ntfy_tags']);
    }
    if (!empty($config['ntfy_click'])) {
      $h['X-Click'] = Cast::toString($config['ntfy_click']);
    }
    if (!empty($config['ntfy_markdown'])) {
      $h['X-Markdown'] = 'yes';
    }
    return $h;
  }

  /**
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string}>
   */
  public function getConfigSchema(): array {
    return [
      'ntfy_topic' => ['label' => E::ts('Topic'), 'required' => TRUE, 'type' => 'string'],
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
