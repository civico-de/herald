<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;
use GuzzleHttp\ClientInterface;

/**
 * Generic outbound webhook channel.
 *
 * Sends the rendered body via HTTP POST (or PUT) to a configurable URL.
 * Body shape is selectable so the same channel covers Slack/Discord/
 * Mattermost incoming webhooks plus arbitrary custom endpoints:
 *
 *   body_format = 'text'    → request body is the rendered text (PlainText)
 *   body_format = 'json'    → {"text": "<rendered HTML>"}              (default key)
 *   body_format = 'slack'   → {"text": "<text, & < > escaped>"}       (Slack incoming webhook)
 *   body_format = 'discord' → {"content": "<text>"}                    (Discord webhooks)
 *   body_format = 'json'    + body_key='msg' → {"msg": "<rendered HTML>"} (custom key)
 *
 * Config:
 *   webhook_url           string  Endpoint (required)
 *   webhook_method        string  HTTP method, default POST
 *   webhook_body_format   string  text|json|slack|discord (default text)
 *   webhook_body_key      string  JSON key when body_format=json (default 'text')
 *   webhook_headers       array   Extra request headers (assoc, name → value)
 *   webhook_auth_token    string  If set, sent as `Authorization: Bearer <token>`
 *
 * The channel's token and headers belong to its URL: a delivery with its own
 * URL (a subscriber's webhook) never gets them (bound_to).
 */
class WebhookChannel implements ChannelInterface {

  private ?ClientInterface $httpClient;

  /**
   * @var null|callable(string): list<string> */
  private $hostResolver;

  /**
   * @param null|callable(string): list<string> $hostResolver
   *   Host-to-IP resolver seam; defaults to system DNS. Injected in tests so
   *   the SSRF guard is exercised without touching real DNS.
   */
  public function __construct(?ClientInterface $httpClient = NULL, ?callable $hostResolver = NULL) {
    $this->httpClient = $httpClient;
    $this->hostResolver = $hostResolver;
  }

  public function getKey(): string {
    return 'webhook';
  }

  /**
   * Why the URL may not be posted to under the site's allowed hosts, or NULL.
   */
  public static function violation(string $url): ?string {
    $allowed = \Civi::settings()->get('herald_webhook_allowed_hosts');
    try {
      WebhookUrlGuard::assert($url, ['allowed_hosts' => is_array($allowed) ? $allowed : []]);
    }
    catch (\CRM_Core_Exception $e) {
      return $e->getMessage();
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $config
   */
  public function send(string $renderedBody, array $config): void {
    $effectiveUrl = Cast::toString($config['webhook_url'] ?? '');
    $token = Cast::toString($config['webhook_auth_token'] ?? '');
    if ($effectiveUrl === '') {
      throw new \CRM_Core_Exception('herald: no webhook_url, neither on the delivery nor on the channel.');
    }
    $allowedHosts = \Civi::settings()->get('herald_webhook_allowed_hosts');
    WebhookUrlGuard::assert($effectiveUrl, [
      'allowed_hosts' => is_array($allowedHosts) ? $allowedHosts : [],
      'resolver' => $this->hostResolver,
    ]);

    $method = strtoupper(Cast::toString($config['webhook_method'] ?? 'POST'));
    $format = Cast::toString($config['webhook_body_format'] ?? 'text');
    $payload = self::buildPayload($renderedBody, $format, Cast::toString($config['webhook_body_key'] ?? 'text'));
    $headers = WebhookHeaders::of($config['webhook_headers'] ?? [], $token);
    // Redirects disabled: a validated public URL could otherwise 302 to a
    // private/link-local address (redirect-based SSRF). Endpoints needing a
    // redirect must be given their final URL.
    $requestOptions = (
      $payload['type'] === 'json'
        ? ['json' => $payload['data'], 'headers' => $headers]
        : ['body' => $payload['data'], 'headers' => $headers + ['Content-Type' => 'text/plain; charset=utf-8']]
    ) + ['http_errors' => FALSE, 'timeout' => 10, 'allow_redirects' => FALSE];

    $client = $this->httpClient ?? new \GuzzleHttp\Client();
    try {
      $response = $client->request($method, $effectiveUrl, $requestOptions);
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception('herald: webhook request failed: ' . $e->getMessage(), 0, [], $e);
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: webhook returned HTTP %d. Body: %s',
        $status,
        substr((string) $response->getBody(), 0, 500),
      ));
    }
  }

  /**
   * Pure builder for the request body. Test-seam.
   *
   * @return array{type: 'json'|'raw', data: mixed}
   */
  public static function buildPayload(string $body, string $format, string $jsonKey = 'text'): array {
    // json hands the HTML body to the receiver; the chat formats show text,
    // and Slack reads `&`, `<`, `>` as control characters.
    return match ($format) {
      'json' => ['type' => 'json', 'data' => [$jsonKey => $body]],
      'slack' => [
        'type' => 'json',
        'data' => ['text' => htmlspecialchars(PlainText::fromHtml($body), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')],
      ],
      'discord' => ['type' => 'json', 'data' => ['content' => PlainText::fromHtml($body)]],
      default => ['type' => 'raw', 'data' => PlainText::fromHtml($body)],
    };
  }

  /**
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string, scope?: 'channel'|'delivery', secret?: bool, recipient?: bool, bound_to?: string}>
   */
  public function getConfigSchema(): array {
    return [
      'webhook_url' => ['label' => E::ts('URL'), 'required' => TRUE, 'type' => 'string', 'recipient' => TRUE],
      'webhook_method' => [
        'label' => E::ts('Method'),
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['POST' => 'POST', 'PUT' => 'PUT'],
        'default' => 'POST',
      ],
      'webhook_body_format' => [
        'label' => E::ts('Body format'),
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['text' => 'text', 'json' => 'json', 'slack' => 'slack', 'discord' => 'discord'],
        'default' => 'text',
      ],
      'webhook_body_key' => [
        'label' => E::ts('JSON key (when body_format=json)'),
        'required' => FALSE,
        'type' => 'string',
      ],
      'webhook_headers' => [
        'label' => E::ts('Extra headers'),
        'required' => FALSE,
        'type' => 'object',
        'scope' => 'channel',
        'secret' => TRUE,
        'bound_to' => 'webhook_url',
      ],
      'webhook_auth_token' => [
        'label' => E::ts('Bearer token (Authorization header)'),
        'required' => FALSE,
        'type' => 'string',
        'scope' => 'channel',
        'secret' => TRUE,
        'bound_to' => 'webhook_url',
      ],
    ];
  }

  /**
   * A webhook payload carries no subject, and webhook_body_format selects the
   * envelope (text/json/slack/discord), not how the body text is to be
   * interpreted — so neither subject nor format maps.
   *
   * @return array<string, string>
   */
  public function getSemanticFields(): array {
    return ['recipient' => 'webhook_url'];
  }

}
