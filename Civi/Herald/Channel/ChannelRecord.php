<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The shape of one record in the `herald_channels` setting, as read from the
 * setting and as built from a saved record.
 */
final class ChannelRecord {

  /**
   * @param array<array-key, mixed> $record
   * @return array{type: string, label: string, active: bool, subscribable: bool, config: array<string, mixed>}
   */
  public static function read(array $record): array {
    return [
      'type' => Cast::toString($record['type'] ?? ''),
      'label' => Cast::toString($record['label'] ?? ''),
      'active' => !empty($record['active']),
      'subscribable' => !empty($record['subscribable']),
      'config' => self::config($record),
    ];
  }

  /**
   * A record to store: the label defaults to the name, `active` to TRUE.
   *
   * @param array<array-key, mixed> $record
   * @param array<string, mixed> $config
   * @return array{type: string, label: string, active: bool, subscribable: bool, config: array<string, mixed>}
   */
  public static function stored(string $name, string $type, array $record, array $config): array {
    return [
      'type' => $type,
      'label' => trim(Cast::toString($record['label'] ?? '')) ?: $name,
      'active' => !array_key_exists('active', $record) || !empty($record['active']),
      'subscribable' => !empty($record['subscribable']),
      'config' => $config,
    ];
  }

  /**
   * @param array<array-key, mixed> $record
   * @return array<string, mixed>
   */
  public static function config(array $record): array {
    return Cast::namedOnly(is_array($record['config'] ?? NULL) ? $record['config'] : []);
  }

}
