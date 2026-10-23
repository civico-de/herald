<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Subscribers\OptionSync;

/**
 * Keeps the channel options of a subscription in step with the
 * active subscribable channels of every domain.
 */
final class PreferredChannelOptions {

  public const OPTION_GROUP = 'herald_preferred_channel';

  public static function sync(): void {
    OptionSync::apply(self::OPTION_GROUP, static function (int $domainId): array {
      $offered = [];
      foreach ((new ChannelStore(NULL, \Civi::settings($domainId)))->all() as $name => $record) {
        if (!$record['active'] || !$record['subscribable']) {
          continue;
        }
        $offered[$name] = $record['label'];
      }
      return $offered;
    });
  }

  /**
   * Settings options: the email channels by name.
   *
   * @return array<string, string>
   */
  public static function emailChannels(): array {
    $email = array_filter((new ChannelStore())->all(), static fn (array $record): bool => $record['type'] === 'email');
    return array_map(static fn (array $record): string => $record['label'], $email);
  }

}
