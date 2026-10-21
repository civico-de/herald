<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Brings an option group of the contact preferences in line with what herald
 * offers, over every domain: contacts span domains, and the current domain's
 * title wins for a name several domains share.
 */
final class OptionSync {

  /**
   * @param callable(int): array<string, string> $offered Domain id => name => title
   */
  public static function apply(string $group, callable $offered): void {
    $wanted = [];
    foreach (self::domains() as $domainId) {
      $wanted += $offered($domainId);
    }
    /** @var array<string, array{id: int, label: string}> $existing */
    $existing = \Civi\Api4\OptionValue::get(checkPermissions: FALSE)
      ->addSelect('id', 'value', 'label')
      ->addWhere('option_group_id:name', '=', $group)
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
        ->addValue('option_group_id:name', $group)
        ->addValue('value', $name)
        ->addValue('name', $name)
        ->addValue('label', $label)
        ->execute();
    }
  }

  /**
   * @return list<int> The current domain first
   */
  private static function domains(): array {
    $ids = array_map(
      Cast::toInt(...),
      \Civi\Api4\Domain::get(checkPermissions: FALSE)->addSelect('id')->execute()->column('id'),
    );
    return array_values(array_unique([\CRM_Core_Config::domainID(), ...$ids]));
  }

}
