<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;
use GuzzleHttp\ClientInterface;

/**
 * Pushover channel — POSTs to https://api.pushover.net/1/messages.json.
 *
 * Pushover uses URL-encoded form data (not JSON). The `token` (app token) and
 * `user` (user/group key) are required by the API; both come from settings
 * unless overridden per-call.
 *
 * Per-call config (from action params / channel_config JSON):
 *   pushover_user_key   string Override the default user/group key
 *   pushover_title      string Notification title
 *   pushover_priority   int    -2 (silent) … 2 (emergency)
 *   pushover_sound      string Sound name (see Pushover docs)
 *   pushover_url        string Supplementary URL shown under the body
 *   pushover_url_title  string Label for `pushover_url`
 *   pushover_html       bool   1 = send the HTML body (Pushover subset); else PlainText
 *   pushover_retry      int    Emergency only: seconds between re-alerts (min 30, default 60)
 *   pushover_expire     int    Emergency only: seconds until re-alerting stops (max 10800, default 3600)
 *
 * Settings:
 *   herald_pushover_app_token        — Pushover application token (required)
 *   herald_pushover_default_user_key — fallback user/group key
 */
class PushoverChannel implements ChannelInterface {

  private const ENDPOINT = 'https://api.pushover.net/1/messages.json';

  private ?ClientInterface $httpClient;

  public function __construct(?ClientInterface $httpClient = NULL) {
    $this->httpClient = $httpClient;
  }

  public function getKey(): string {
    return 'pushover';
  }

  /**
   * @param array<string, mixed> $config
   */
  public function send(string $renderedBody, array $config): void {
    $appToken = Cast::toString(\Civi::settings()->get('herald_pushover_app_token'));
    if ($appToken === '') {
      throw new \CRM_Core_Exception('herald: Pushover app token not configured (herald_pushover_app_token).');
    }
    // !empty, not ??: an empty pushover_user_key must still fall back to the setting.
    $userKey = !empty($config['pushover_user_key'])
      ? $config['pushover_user_key']
      : \Civi::settings()->get('herald_pushover_default_user_key');
    if (!$userKey) {
      throw new \CRM_Core_Exception(
        'herald: Pushover user/group key required (pushover_user_key action param or herald_pushover_default_user_key setting).',
      );
    }

    $form = PushoverForm::of($appToken, Cast::toString($userKey), $renderedBody, $config);

    $client = $this->httpClient ?? new \GuzzleHttp\Client();
    try {
      $response = $client->request('POST', self::ENDPOINT, [
        'form_params' => $form,
        'http_errors' => FALSE,
        'timeout' => 10,
      ]);
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception('herald: Pushover request failed: ' . $e->getMessage(), 0, [], $e);
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    if ($status < 200 || $status >= 300) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: Pushover returned HTTP %d. Body: %s',
        $status,
        substr($body, 0, 500),
      ));
    }
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded) || Cast::toInt($decoded['status'] ?? 0) !== 1) {
      throw new \CRM_Core_Exception('herald: Pushover responded with status != 1. Body: ' . substr($body, 0, 500));
    }
  }

  /**
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string}>
   */
  public function getConfigSchema(): array {
    return [
      'pushover_user_key' => [
        'label' => E::ts('User/group key (override default)'),
        'required' => FALSE,
        'type' => 'string',
      ],
      'pushover_title' => ['label' => E::ts('Title'), 'required' => FALSE, 'type' => 'string'],
      'pushover_priority' => ['label' => E::ts('Priority (-2…2)'), 'required' => FALSE, 'type' => 'int'],
      'pushover_retry' => [
        'label' => E::ts('Emergency retry interval in seconds (priority 2 only)'),
        'required' => FALSE,
        'type' => 'int',
      ],
      'pushover_expire' => [
        'label' => E::ts('Emergency expiry in seconds (priority 2 only)'),
        'required' => FALSE,
        'type' => 'int',
      ],
      'pushover_sound' => ['label' => E::ts('Sound'), 'required' => FALSE, 'type' => 'string'],
      'pushover_url' => ['label' => E::ts('Supplementary URL'), 'required' => FALSE, 'type' => 'string'],
      'pushover_url_title' => ['label' => E::ts('URL label'), 'required' => FALSE, 'type' => 'string'],
      'pushover_html' => ['label' => E::ts('Body is HTML'), 'required' => FALSE, 'type' => 'bool'],
    ];
  }

  /**
   * No 'format': pushover_html is a boolean flag, not a 'text'|'html' enum, so
   * the semantic value cannot be passed through unchanged.
   *
   * @return array<string, string>
   */
  public function getSemanticFields(): array {
    return ['subject' => 'pushover_title'];
  }

}
