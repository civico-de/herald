<?php

declare(strict_types = 1);

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
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:kan-defect
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
   * @param array<string, mixed> $config
   */
  public function send(string $renderedBody, array $config): void {
    // !empty, not ??: an empty webhook_url in the config must still fall
    // back to the default setting.
    $urlFromConfig = !empty($config['webhook_url']);
    $url = $urlFromConfig ? $config['webhook_url'] : \Civi::settings()->get('herald_webhook_default_url');
    if (!$url) {
      throw new \CRM_Core_Exception(
        'herald: webhook channel requires webhook_url (action param or herald_webhook_default_url setting).',
      );
    }

    $effectiveUrl = self::str($url);
    $allowedHosts = \Civi::settings()->get('herald_webhook_allowed_hosts');
    self::assertUrlAllowed($effectiveUrl, [
      'allowed_hosts' => is_array($allowedHosts) ? $allowedHosts : [],
      'resolver' => $this->hostResolver,
    ]);

    $method = strtoupper(self::str($config['webhook_method'] ?? 'POST'));
    $format = self::str($config['webhook_body_format'] ?? 'text');
    $payload = self::buildPayload($renderedBody, $format, self::str($config['webhook_body_key'] ?? 'text'));

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
        if (strcasecmp($h, 'Authorization') !== 0) {
          continue;
        }

        $hasAuth = TRUE;
        break;
      }
      if (!$hasAuth) {
        $headers['Authorization'] = 'Bearer ' . self::str($authToken);
      }
    }

    // Redirects disabled: a validated public URL could otherwise 302 to a
    // private/link-local address (redirect-based SSRF). Endpoints needing a
    // redirect must be given their final URL.
    $requestOptions = ['http_errors' => FALSE, 'timeout' => 10, 'headers' => $headers, 'allow_redirects' => FALSE];
    if ($payload['type'] === 'json') {
      $requestOptions['json'] = $payload['data'];
    }
    // @mago-expect lint:no-else-clause
    else {
      $requestOptions['body'] = $payload['data'];
      $requestOptions['headers']['Content-Type'] = $headers['Content-Type'] ?? 'text/plain; charset=utf-8';
    }

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
    return match ($format) {
      'json' => ['type' => 'json', 'data' => [$jsonKey => $body]],
      'slack' => ['type' => 'json', 'data' => ['text' => $body]],
      'discord' => ['type' => 'json', 'data' => ['content' => $body]],
      default => ['type' => 'raw', 'data' => $body],
    };
  }

  /**
   * SSRF guard. Pure, static, test-seam like buildPayload: rejects a URL whose
   * scheme is not http/https, carries credentials, or resolves to any
   * private/reserved/link-local/metadata address. An optional non-empty
   * allowed_hosts list locks delivery to known endpoints and bypasses the
   * denylist for a matching host.
   *
   * @param array{allowed_hosts?: array<int|string, mixed>, resolver?: null|callable(string): list<string>} $opts
   */
  public static function assertUrlAllowed(string $url, array $opts = []): void {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      throw new \CRM_Core_Exception('herald: webhook URL is not a valid absolute URL.');
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
      throw new \CRM_Core_Exception('herald: webhook URL must use http or https, got ' . $scheme . '.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      throw new \CRM_Core_Exception('herald: webhook URL must not contain credentials.');
    }

    $host = trim((string) $parts['host'], '[]');

    $allowed = $opts['allowed_hosts'] ?? [];
    if (is_array($allowed) && $allowed !== []) {
      $lcHost = strtolower($host);
      foreach ($allowed as $entry) {
        $needle = strtolower(trim(self::str($entry)));
        if ($needle === '') {
          continue;
        }
        if ($lcHost === $needle || str_ends_with($lcHost, '.' . $needle)) {
          return;
        }
      }
      throw new \CRM_Core_Exception('herald: webhook host is not in herald_webhook_allowed_hosts.');
    }

    $resolver = $opts['resolver'] ?? NULL;
    $ips = self::resolveHost($host, is_callable($resolver) ? $resolver : NULL);
    if ($ips === []) {
      throw new \CRM_Core_Exception('herald: could not resolve webhook host ' . $host . '.');
    }
    foreach ($ips as $ip) {
      if (self::ipIsBlocked($ip)) {
        throw new \CRM_Core_Exception('herald: webhook host resolves to a disallowed address.');
      }
    }
  }

  /**
   * @param null|callable(string): list<string> $resolver
   * @return list<string>
   */
  private static function resolveHost(string $host, ?callable $resolver): array {
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return [$host];
    }

    try {
      return $resolver !== NULL ? $resolver($host) : self::resolveHostUsingDns($host);
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception(
        'herald: DNS lookup failed for webhook host ' . $host . '.',
        0,
        [],
        $e,
      );
    }
  }

  /**
   * Resolve both address families in one DNS query.
   *
   * PHP reports resolver failures as warnings and FALSE. Convert only warnings
   * raised during this one DNS call into an exception so the SSRF guard fails
   * closed, then immediately restore the previous application error handler.
   *
   * @return list<string>
   */
  private static function resolveHostUsingDns(string $host): array {
    set_error_handler(
      static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
      },
      E_WARNING,
    );
    try {
      $records = dns_get_record($host, DNS_A | DNS_AAAA);
    }
    finally {
      restore_error_handler();
    }

    if ($records === FALSE) {
      throw new \RuntimeException('DNS lookup returned false.');
    }

    $ips = [];
    foreach ($records as $record) {
      foreach (['ip', 'ipv6'] as $field) {
        $ip = $record[$field] ?? NULL;
        if (is_string($ip) && $ip !== '') {
          $ips[] = $ip;
        }
      }
    }

    return array_values(array_unique($ips));
  }

  /**
   * filter_var's private/reserved flags miss link-local (169.254/16) and
   * IPv6 ULA/link-local in some PHP builds, so those are checked explicitly.
   */
  private static function ipIsBlocked(string $ip): bool {
    if (stripos($ip, '::ffff:') === 0 && substr_count($ip, '.') === 3) {
      $ip = substr($ip, (int) strrpos($ip, ':') + 1);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP) === FALSE) {
      return TRUE;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE) {
      return TRUE;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE) {
      $long = ip2long($ip);
      // 169.254/16 link-local (incl. 169.254.169.254 metadata) and 0.0.0.0/8.
      return $long !== FALSE && (($long & 0xFFFF_0000) === (int) ip2long('169.254.0.0') || ($long >> 24) === 0);
    }
    $lower = strtolower($ip);
    if ($lower === '::1' || $lower === '::') {
      return TRUE;
    }
    // fc00::/7 (fc/fd) ULA and fe80::/10 (fe8-feb) link-local.
    return (
      str_starts_with($lower, 'fc')
      || str_starts_with($lower, 'fd')
      || str_starts_with($lower, 'fe8')
      || str_starts_with($lower, 'fe9')
      || str_starts_with($lower, 'fea')
      || str_starts_with($lower, 'feb')
    );
  }

  /**
   * @return array<string, string> */
  private static function normaliseHeaders(mixed $raw): array {
    if (is_string($raw)) {
      $decoded = json_decode($raw, TRUE);
      $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
      return [];
    }
    $out = [];
    foreach ($raw as $name => $value) {
      if (!(is_string($name) && $name !== '')) {
        continue;
      }

      $out[$name] = self::str($value);
    }
    return $out;
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
      'webhook_url' => ['label' => 'URL', 'required' => TRUE, 'type' => 'string'],
      'webhook_method' => [
        'label' => 'Method',
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['POST' => 'POST', 'PUT' => 'PUT'],
        'default' => 'POST',
      ],
      'webhook_body_format' => [
        'label' => 'Body format',
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['text' => 'text', 'json' => 'json', 'slack' => 'slack', 'discord' => 'discord'],
        'default' => 'text',
      ],
      'webhook_body_key' => ['label' => 'JSON key (when body_format=json)', 'required' => FALSE, 'type' => 'string'],
      'webhook_headers' => ['label' => 'Extra headers', 'required' => FALSE, 'type' => 'object'],
      'webhook_auth_token' => [
        'label' => 'Bearer token (Authorization header)',
        'required' => FALSE,
        'type' => 'string',
      ],
    ];
  }

  /**
   * A webhook payload carries no subject, and webhook_body_format selects the
   * envelope (text/json/slack/discord), not how the body text is to be
   * interpreted — so neither semantic maps.
   *
   * @return array<string, string>
   */
  public function getSemanticFields(): array {
    return [];
  }

}
