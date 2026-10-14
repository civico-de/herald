<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The config a channel gets from a caller's params: every key passed
 * through, `channel_config` merged on top, routing keys removed.
 */
final class ChannelConfig {

  /**
   * Presentation-only options forwarded to subscriber and group sends.
   * Recipient and credential keys (chat_id, webhook_url, webhook_auth_token,
   * webhook_headers, ntfy_topic, pushover_user_key, …) come from each
   * contact's own preferences and are never sent to a contact's endpoint.
   */
  private const SUBSCRIBER_SAFE_KEYS = [
    'parse_mode',
    'disable_notification',
    'disable_web_page_preview',
    'webhook_body_format',
    'webhook_body_key',
    'ntfy_title',
    'ntfy_priority',
    'ntfy_tags',
    'ntfy_click',
    'ntfy_markdown',
    'pushover_title',
    'pushover_priority',
    'pushover_sound',
    'pushover_url',
    'pushover_url_title',
    'pushover_html',
    'pushover_retry',
    'pushover_expire',
    'email_subject',
    'email_format',
  ];

  /**
   * Channels read whichever keys they know (`chat_id`, `webhook_url`,
   * `ntfy_topic`, …); a key in `channel_config` wins over the same key at
   * the top level.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function of(array $params): array {
    $config = array_merge($params, self::overrides($params['channel_config'] ?? NULL));
    unset($config['channel_config'], $config['channel'], $config['template']);
    return Cast::namedOnly($config);
  }

  /**
   * Reduce a channel config to the keys allowed in subscriber and group sends.
   *
   * @param array<array-key, mixed> $config
   * @return array<string, mixed>
   */
  public static function subscriberSafe(array $config): array {
    return Cast::namedOnly(array_intersect_key($config, array_flip(self::SUBSCRIBER_SAFE_KEYS)));
  }

  /**
   * Default email_subject to the template's rendered subject. An explicit
   * email_subject wins, as does one in `channel_config`, which of() merges later.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function withSubject(array $params, ?string $subject): array {
    if ($subject !== NULL && $subject !== '' && empty($params['email_subject'])) {
      $params['email_subject'] = $subject;
    }
    return $params;
  }

  /**
   * @return array<array-key, mixed>
   */
  private static function overrides(mixed $raw): array {
    if ($raw === NULL || $raw === '' || is_array($raw)) {
      return (array) $raw;
    }
    $decoded = is_string($raw) ? json_decode($raw, TRUE) : NULL;
    if (!is_array($decoded)) {
      // The value may hold tokens, so the message names only its type.
      throw new \CRM_Core_Exception(sprintf(
        'herald: channel_config must be a JSON object, not %s.',
        is_string($raw) ? 'this text' : get_debug_type($raw),
      ));
    }
    return $decoded;
  }

}
