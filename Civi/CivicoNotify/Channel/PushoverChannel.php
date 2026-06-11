<?php

namespace Civi\CivicoNotify\Channel;

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
 *   pushover_html       bool   1 = body is HTML (Pushover's limited subset)
 *
 * Settings:
 *   civico_notify_pushover_app_token        — Pushover application token (required)
 *   civico_notify_pushover_default_user_key — fallback user/group key
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

  public function send(string $renderedBody, array $config): void {
    $appToken = (string) \Civi::settings()->get('civico_notify_pushover_app_token');
    if ($appToken === '') {
      throw new \CRM_Core_Exception('civico_notify: Pushover app token not configured (civico_notify_pushover_app_token).');
    }
    $userKey = $config['pushover_user_key'] ?? \Civi::settings()->get('civico_notify_pushover_default_user_key');
    if (!$userKey) {
      throw new \CRM_Core_Exception('civico_notify: Pushover user/group key required (pushover_user_key action param or civico_notify_pushover_default_user_key setting).');
    }

    $form = self::buildForm($appToken, (string) $userKey, $renderedBody, $config);

    $client = $this->httpClient ?? new \GuzzleHttp\Client();
    try {
      $response = $client->request('POST', self::ENDPOINT, [
        'form_params' => $form,
        'http_errors' => FALSE,
        'timeout' => 10,
      ]);
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception('civico_notify: Pushover request failed: ' . $e->getMessage(), 0, [], $e);
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    if ($status < 200 || $status >= 300) {
      throw new \CRM_Core_Exception(sprintf(
        'civico_notify: Pushover returned HTTP %d. Body: %s',
        $status,
        substr($body, 0, 500)
      ));
    }
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded) || (int) ($decoded['status'] ?? 0) !== 1) {
      throw new \CRM_Core_Exception('civico_notify: Pushover responded with status != 1. Body: ' . substr($body, 0, 500));
    }
  }

  /**
   * Pure form-payload builder. Test-seam.
   *
   * @return array<string, scalar>
   */
  public static function buildForm(string $appToken, string $userKey, string $body, array $config): array {
    $form = [
      'token' => $appToken,
      'user' => $userKey,
      'message' => $body,
    ];
    if (!empty($config['pushover_title'])) {
      $form['title'] = (string) $config['pushover_title'];
    }
    if (isset($config['pushover_priority']) && $config['pushover_priority'] !== '') {
      $form['priority'] = (int) $config['pushover_priority'];
    }
    if (!empty($config['pushover_sound'])) {
      $form['sound'] = (string) $config['pushover_sound'];
    }
    if (!empty($config['pushover_url'])) {
      $form['url'] = (string) $config['pushover_url'];
    }
    if (!empty($config['pushover_url_title'])) {
      $form['url_title'] = (string) $config['pushover_url_title'];
    }
    if (!empty($config['pushover_html'])) {
      $form['html'] = 1;
    }
    return $form;
  }

  public function getConfigSchema(): array {
    return [
      'pushover_user_key' => ['label' => 'User/group key (override default)', 'required' => FALSE, 'type' => 'string'],
      'pushover_title' => ['label' => 'Title', 'required' => FALSE, 'type' => 'string'],
      'pushover_priority' => ['label' => 'Priority (-2…2)', 'required' => FALSE, 'type' => 'int'],
      'pushover_sound' => ['label' => 'Sound', 'required' => FALSE, 'type' => 'string'],
      'pushover_url' => ['label' => 'Supplementary URL', 'required' => FALSE, 'type' => 'string'],
      'pushover_url_title' => ['label' => 'URL label', 'required' => FALSE, 'type' => 'string'],
      'pushover_html' => ['label' => 'Body is HTML', 'required' => FALSE, 'type' => 'bool'],
    ];
  }

}
