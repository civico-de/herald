<?php

declare(strict_types = 1);

namespace Civi\Herald;

use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Channel\PreferredChannelOptions;

/**
 * Channels for tests, saved the way Herald.saveChannel saves them.
 */
final class ChannelFixture {

  /**
   * Add the channel, or replace one of that name.
   *
   * @param array<string, mixed> $config
   */
  public static function add(string $name, string $type, array $config = [], bool $subscribable = FALSE): void {
    $store = new ChannelStore();
    $record = ['type' => $type, 'label' => $name, 'subscribable' => $subscribable, 'config' => $config];
    $errors = isset($store->all()[$name]) ? $store->replace($name, $record) : $store->create($name, $record);
    if ($errors !== []) {
      throw new \RuntimeException(implode("\n", $errors));
    }
    PreferredChannelOptions::sync();
  }

}
