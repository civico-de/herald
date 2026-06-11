<?php

namespace Civi\Herald\Channel;

use GuzzleHttp\ClientInterface;

/**
 * Generic outbound webhook channel.
 *
 * Sends the rendered body via HTTP POST (or PUT) to a configurable URL.
 * Body shape is selectable so the same channel covers Slack/Discord/
 * Mattermost incoming webhooks plus arbitrary custom endpoints:
 *
 *   body_format = 'text'    → request body is the rendered string verbatim
 *   body_format = 'json'    → {"text": "<rendered>"}                   (default key)
 *   body_format = 'slack'   → {"text": "<rendered>"}                   (Slack-style alias)
 *   body_format = 'discord' → {"content": "<rendered>"}                (Discord webhooks)
 *   body_format = 'json'    + body_key='msg' → {"msg": "<rendered>"}   (custom key)
 *
 * Per-call config (`webhook_*` keys merged from action params /
 * channel_config JSON):
 *   webhook_url           string  Endpoint (required; falls back to default setting)
 *   webhook_method        string  HTTP method, default POST
 *   webhook_body_format   string  text|json|slack|discord (default text)
 *   webhook_body_key      string  JSON key when body_format=json (default 'text')
 *   webhook_headers       array   Extra request headers (assoc, name → value)
 *   webhook_auth_token    string  If set, sent as `Authorization: Bearer <token>`
 *
 * Settings:
 *   herald_webhook_default_url        — fallback URL when not in action
 *   herald_webhook_default_auth_token — fallback bearer token; only
 *     applied when the URL also came from the default setting. The default
 *     token belongs to the default endpoint — it must never be attached to a
 *     caller-supplied URL (e.g. a subscriber's personal webhook).
 */
class WebhookChannel implements ChannelInterface {

  private ?ClientInterface $httpClient;

  public function __construct(?ClientInterface $httpClient = NULL) {
    $this->httpClient = $httpClient;
  }

  public function getKey(): string {
    return 'webhook';
  }

  public function send(string $renderedBody, array $config): void {
    // !empty, not ??: an empty webhook_url in the config must still fall
    // back to the default setting.
    $urlFromConfig = !empty($config['webhook_url']);
    $url = $urlFromConfig ? $config['webhook_url'] : \Civi::settings()->get('herald_webhook_default_url');
    if (!$url) {
      throw new \CRM_Core_Exception('herald: webhook channel requires webhook_url (action param or herald_webhook_default_url setting).');
    }

    $method = strtoupper((string) ($config['webhook_method'] ?? 'POST'));
    $format = (string) ($config['webhook_body_format'] ?? 'text');
    $payload = self::buildPayload($renderedBody, $format, (string) ($config['webhook_body_key'] ?? 'text'));

    $headers = self::normaliseHeaders($config['webhook_headers'] ?? []);
    // The default token is paired with the default URL: it must not leak to
    // caller-supplied endpoints (subscriber-mode contacts control their URL).
    $authToken = $config['webhook_auth_token'] ?? NULL;
    if (!$authToken && !$urlFromConfig) {
      $authToken = \Civi::settings()->get('herald_webhook_default_auth_token');
    }
    if ($authToken) {
      // Don't overwrite an explicitly-set Authorization header — admins can
      // still pass `Authorization: Basic ...` via webhook_headers and skip the
      // shorthand.
      $hasAuth = FALSE;
      foreach (array_keys($headers) as $h) {
        if (strcasecmp($h, 'Authorization') === 0) {
          $hasAuth = TRUE;
          break;
        }
      }
      if (!$hasAuth) {
        $headers['Authorization'] = 'Bearer ' . $authToken;
      }
    }

    $requestOptions = ['http_errors' => FALSE, 'timeout' => 10, 'headers' => $headers];
    if ($payload['type'] === 'json') {
      $requestOptions['json'] = $payload['data'];
    }
    else {
      $requestOptions['body'] = $payload['data'];
      $requestOptions['headers']['Content-Type'] = $headers['Content-Type'] ?? 'text/plain; charset=utf-8';
    }

    $client = $this->httpClient ?? new \GuzzleHttp\Client();
    try {
      $response = $client->request($method, (string) $url, $requestOptions);
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception('herald: webhook request failed: ' . $e->getMessage(), 0, [], $e);
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: webhook returned HTTP %d. Body: %s',
        $status,
        substr((string) $response->getBody(), 0, 500)
      ));
    }
  }

  /**
   * Pure builder for the request body. Test-seam.
   *
   * @return array{type: 'json'|'raw', data: mixed}
   */
  public static function buildPayload(string $body, string $format, string $jsonKey = 'text'): array {
    return match ($format) {
      'json' => ['type' => 'json', 'data' => [$jsonKey => $body]],
      'slack' => ['type' => 'json', 'data' => ['text' => $body]],
      'discord' => ['type' => 'json', 'data' => ['content' => $body]],
      default => ['type' => 'raw', 'data' => $body],
    };
  }

  /** @return array<string, string> */
  private static function normaliseHeaders($raw): array {
    if (is_string($raw)) {
      $decoded = json_decode($raw, TRUE);
      $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
      return [];
    }
    $out = [];
    foreach ($raw as $name => $value) {
      if (is_string($name) && $name !== '') {
        $out[$name] = (string) $value;
      }
    }
    return $out;
  }

  public function getConfigSchema(): array {
    return [
      'webhook_url' => ['label' => 'URL', 'required' => TRUE, 'type' => 'string'],
      'webhook_method' => ['label' => 'Method', 'required' => FALSE, 'type' => 'enum', 'options' => ['POST' => 'POST', 'PUT' => 'PUT'], 'default' => 'POST'],
      'webhook_body_format' => ['label' => 'Body format', 'required' => FALSE, 'type' => 'enum', 'options' => ['text' => 'text', 'json' => 'json', 'slack' => 'slack', 'discord' => 'discord'], 'default' => 'text'],
      'webhook_body_key' => ['label' => 'JSON key (when body_format=json)', 'required' => FALSE, 'type' => 'string'],
      'webhook_headers' => ['label' => 'Extra headers', 'required' => FALSE, 'type' => 'object'],
      'webhook_auth_token' => ['label' => 'Bearer token (Authorization header)', 'required' => FALSE, 'type' => 'string'],
    ];
  }

}
