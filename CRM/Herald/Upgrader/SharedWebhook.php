<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ChannelSecrets;
use Civi\Herald\Channel\ChannelStore;

/**
 * The webhook channel a delivery's secrets move into, in every domain it may
 * be sent from, found again for a delivery with equal ones.
 */
final class CRM_Herald_Upgrader_SharedWebhook {

  /**
   * The channel an earlier delivery with the same URL and secrets moved into
   * in every domain, so the alerts a rule buffered share its channel.
   *
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @param array<int, array<string, mixed>> $configs
   * @param list<string> $rejected The errors of the values left out
   */
  public static function name(array $domains, array $configs, array $rejected): ?string {
    $names = [];
    foreach ($domains as $domain) {
      if (!isset($configs[$domain->id])) {
        continue;
      }
      $names[] = self::in($domain->store(), $configs[$domain->id], $rejected);
    }
    $names = array_unique($names);
    return count($names) === 1 ? reset($names) : NULL;
  }

  /**
   * A channel that left out rejected values stays switched off until someone sets them right.
   *
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @param array<int, array<string, mixed>> $configs Domain id => config
   * @param list<string> $rejected The errors of the values left out
   */
  public static function create(array $domains, string $name, array $configs, array $rejected): void {
    $errors = [];
    foreach ($domains as $domain) {
      $store = $domain->store();
      if (!isset($configs[$domain->id]) || isset($store->all()[$name])) {
        continue;
      }
      $errors = array_merge($errors, $store->create($name, [
        'type' => 'webhook',
        'label' => $name,
        'active' => $rejected === [],
        'subscribable' => FALSE,
        'config' => $configs[$domain->id],
      ]));
    }
    if ($errors !== []) {
      throw new CRM_Core_Exception(sprintf(
        'herald: the channel "%s" cannot be created: %s',
        $name,
        implode(' ', array_unique($errors)),
      ));
    }
    if ($rejected !== []) {
      Civi::log()->warning('herald: the channel {name} is switched off, without the values it was given: {errors}', [
        'name' => $name,
        'errors' => implode(' ', $rejected),
      ]);
    }
  }

  /**
   * @param array<string, mixed> $config
   * @param list<string> $rejected
   */
  private static function in(ChannelStore $store, array $config, array $rejected): ?string {
    ksort($config);
    $schema = ChannelRegistry::default()->get('webhook')->getConfigSchema();
    foreach ($store->all() as $name => $record) {
      if (
        $record['type'] !== 'webhook'
        || $record['active'] !== ($rejected === [])
        || !str_starts_with($name, 'webhook_')
      ) {
        continue;
      }
      $stored = ChannelSecrets::revealed($schema, $record['config']);
      ksort($stored);
      if ($stored === $config) {
        return $name;
      }
    }
    return NULL;
  }

}
