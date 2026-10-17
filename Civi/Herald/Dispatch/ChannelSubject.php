<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Channel\ChannelInterface;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * A message's subject on its way to a channel: carried under a neutral key and
 * named after the channel's own subject key (email_subject, ntfy_title, …) last.
 */
final class ChannelSubject {

  /**
   * The rendered subject, until forChannel() names it after the channel's key.
   */
  public const KEY = 'subject';

  /**
   * Carry the template's rendered subject to the channel; forChannel() puts it
   * under the channel's own key unless the caller set that key.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function withSubject(array $params, ?string $subject): array {
    if ($subject !== NULL && $subject !== '') {
      $params[self::KEY] = $subject;
    }
    return $params;
  }

  /**
   * The config one channel gets: the carried subject under its subject key.
   *
   * @param array<string, mixed> $config
   * @return array<string, mixed>
   */
  public static function forChannel(ChannelInterface $channel, array $config): array {
    $subject = Cast::toString($config[self::KEY] ?? '');
    unset($config[self::KEY]);
    return self::withSemantics($config, $channel->getSemanticFields(), $subject, '');
  }

  /**
   * The first channel subject key the config fills, e.g. email_subject, or NULL.
   *
   * @param array<string, mixed> $config
   */
  public static function subjectKeyIn(array $config): ?string {
    $registry = ChannelRegistry::default();
    foreach ($registry->listKeys() as $key) {
      $subjectKey = $registry->get($key)->getSemanticFields()['subject'] ?? NULL;
      if ($subjectKey !== NULL && trim(Cast::toString($config[$subjectKey] ?? '')) !== '') {
        return $subjectKey;
      }
    }
    return NULL;
  }

  /**
   * Name a semantic subject and format after a channel's keys; an explicit
   * entry in $config wins.
   *
   * @param array<string, mixed> $config
   * @param array<string, string> $semanticFields
   * @return array<string, mixed>
   */
  public static function withSemantics(array $config, array $semanticFields, string $subject, string $format): array {
    foreach (['subject' => $subject, 'format' => $format] as $semantic => $value) {
      $key = $semanticFields[$semantic] ?? NULL;
      if ($value !== '' && $key !== NULL && !isset($config[$key])) {
        $config[$key] = $value;
      }
    }
    return $config;
  }

}
