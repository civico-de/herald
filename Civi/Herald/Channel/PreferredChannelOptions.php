<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Keeps the options of a contact's preferred channel in step with the
 * active subscribable channels. Contacts span domains, so the options are the
 * channels of every domain; the current domain's title wins for a shared name.
 */
final class PreferredChannelOptions {

  public const OPTION_GROUP = 'herald_preferred_channel';

  public static function sync(): void {
    $wanted = self::wanted();
    /** @var array<string, array{id: int, label: string}> $existing */
    $existing = \Civi\Api4\OptionValue::get(checkPermissions: FALSE)
      ->addSelect('id', 'value', 'label')
      ->addWhere('option_group_id:name', '=', self::OPTION_GROUP)
      ->execute()
      ->indexBy('value')
      ->getArrayCopy();
    foreach ($existing as $value => $row) {
      if (!isset($wanted[(string) $value])) {
        \Civi\Api4\OptionValue::delete(checkPermissions: FALSE)->addWhere('id', '=', $row['id'])->execute();
        continue;
      }
      if ($row['label'] === $wanted[(string) $value]) {
        continue;
      }
      \Civi\Api4\OptionValue::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $row['id'])
        ->addValue('label', $wanted[(string) $value])
        ->execute();
    }
    foreach (array_diff_key($wanted, $existing) as $name => $label) {
      \Civi\Api4\OptionValue::create(checkPermissions: FALSE)
        ->addValue('option_group_id:name', self::OPTION_GROUP)
        ->addValue('value', $name)
        ->addValue('name', $name)
        ->addValue('label', $label)
        ->execute();
    }
  }

  /**
   * @return array<string, string> Channel name => title
   */
  private static function wanted(): array {
    $domains = array_map(
      Cast::toInt(...),
      \Civi\Api4\Domain::get(checkPermissions: FALSE)->addSelect('id')->execute()->column('id'),
    );
    $wanted = [];
    foreach (array_unique([\CRM_Core_Config::domainID(), ...$domains]) as $domainId) {
      foreach ((new ChannelStore(NULL, \Civi::settings($domainId)))->all() as $name => $record) {
        if (!$record['active'] || !$record['subscribable'] || isset($wanted[$name])) {
          continue;
        }
        $wanted[$name] = $record['label'];
      }
    }
    return $wanted;
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
