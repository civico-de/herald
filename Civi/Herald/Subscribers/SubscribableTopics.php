<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Topic\Router;
use Civi\Herald\Topic\TopicMeta;

/**
 * The topics contacts may subscribe to: the options of a subscription's
 * topics field.
 */
final class SubscribableTopics {

  public const OPTION_GROUP = 'herald_subscribable_topic';

  public static function sync(): void {
    OptionSync::apply(self::OPTION_GROUP, static function (int $domainId): array {
      $map = \Civi::settings($domainId)->get(Router::SETTING);
      return TopicMeta::subscribable(is_array($map) ? $map : []);
    });
  }

  /**
   * Whether the current domain offers the topic for subscription.
   */
  public static function offers(string $topic): bool {
    $map = \Civi::settings()->get(Router::SETTING);
    return isset(TopicMeta::subscribable(is_array($map) ? $map : [])[$topic]);
  }

  /**
   * CONTAINS on a serialized field is a LIKE: the topic's wildcard characters match only themselves.
   */
  public static function pattern(string $topic): string {
    return addcslashes($topic, '\\%_');
  }

}
