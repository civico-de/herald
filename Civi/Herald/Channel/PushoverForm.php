<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The form data a Pushover message is POSTed as.
 */
final class PushoverForm {

  /**
   * Form fields copied as text when set, by config key.
   */
  private const TEXT_FIELDS = [
    'title' => 'pushover_title',
    'sound' => 'pushover_sound',
    'url' => 'pushover_url',
    'url_title' => 'pushover_url_title',
  ];

  /**
   * The form payload of one message.
   *
   * @param array<string, mixed> $config
   * @return array<string, scalar>
   */
  public static function of(
    #[\SensitiveParameter]
    string $appToken,
    string $userKey,
    string $body,
    array $config,
  ): array {
    $form = [
      'token' => $appToken,
      'user' => $userKey,
      'message' => empty($config['pushover_html']) ? PlainText::fromHtml($body) : $body,
    ];
    foreach (self::TEXT_FIELDS as $field => $key) {
      if (empty($config[$key])) {
        continue;
      }
      $form[$field] = Cast::toString($config[$key]);
    }
    if (!empty($config['pushover_html'])) {
      $form['html'] = 1;
    }
    return $form + self::priority($config);
  }

  /**
   * The priority fields; emergency priority needs retry and expire or the API
   * rejects the call.
   *
   * @param array<string, mixed> $config
   * @return array<string, int>
   */
  private static function priority(array $config): array {
    $raw = $config['pushover_priority'] ?? '';
    if ($raw === '') {
      return [];
    }
    $priority = Cast::toInt($raw);
    return (
      $priority !== 2
        ? ['priority' => $priority]
        : [
          'priority' => 2,
          'retry' => max(30, Cast::toInt($config['pushover_retry'] ?? 60)),
          'expire' => min(10_800, max(1, Cast::toInt($config['pushover_expire'] ?? 3600))),
        ]
    );
  }

}
