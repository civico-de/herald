<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The configured channels in the `herald_channels` setting: a name, a type
 * from ChannelRegistry and that type's channel and default delivery keys.
 * Secret keys are stored as CRED tokens and leave the store only through get().
 *
 *   {"ops_bot": {"type": "telegram", "label": "Ops bot", "active": true,
 *     "subscribable": false, "config": {"bot_token": "CTK?…", "chat_id": "-100…"}}}
 */
final class ChannelStore {

  public const SETTING = 'herald_channels';

  public const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

  private ChannelRegistry $registry;

  private \Civi\Core\SettingsBag $settings;

  public function __construct(?ChannelRegistry $registry = NULL, ?\Civi\Core\SettingsBag $settings = NULL) {
    $this->registry = $registry ?? ChannelRegistry::default();
    $this->settings = $settings ?? \Civi::settings();
  }

  /**
   * @return array<string, array{type: string, label: string, active: bool, subscribable: bool, config: array<string, mixed>}>
   */
  public function all(): array {
    $stored = $this->settings->get(self::SETTING);
    $out = [];
    foreach (is_array($stored) ? $stored : [] as $name => $record) {
      if (!is_array($record)) {
        continue;
      }
      $out[(string) $name] = ChannelRecord::read($record);
    }
    return $out;
  }

  /**
   * One active channel, its secrets decrypted.
   *
   * @return array{name: string, type: string, config: array<string, mixed>}
   */
  public function get(string $name): array {
    $record = $this->record($name);
    if (!$record['active']) {
      throw new \CRM_Core_Exception(sprintf('herald: the channel "%s" is switched off.', $name));
    }
    $schema = $this->registry->get($record['type'])->getConfigSchema();
    return [
      'name' => $name,
      'type' => $record['type'],
      'config' => ChannelSecrets::revealed($schema, $record['config']),
    ];
  }

  /**
   * The channel's type, also for a channel that is switched off.
   */
  public function typeOf(string $name): string {
    return $this->record($name)['type'];
  }

  /**
   * The records as admin screens see them: a secret only as `set`.
   *
   * @return list<array<string, mixed>>
   */
  public function masked(): array {
    $rows = [];
    foreach ($this->all() as $name => $record) {
      // Without its type's schema every key counts as secret.
      $schema = $this->registry->has($record['type'])
        ? $this->registry->get($record['type'])->getConfigSchema()
        : array_map(static fn (): array => ['secret' => TRUE], $record['config']);
      $rows[] = ['name' => $name, 'config' => ChannelSecrets::masked($schema, $record['config'])] + $record;
    }
    return $rows;
  }

  /**
   * Validate and add one channel under a free name.
   *
   * @param array<array-key, mixed> $record
   * @return list<string> Validation errors; nothing is stored when there are any.
   */
  public function create(string $name, array $record): array {
    return $this->store($name, $record, ChannelRules::newRecordErrors(...));
  }

  /**
   * Validate and replace a stored channel of the same type. A secret left out
   * or empty keeps its stored value, NULL clears it.
   *
   * @param array<array-key, mixed> $record
   * @return list<string> Validation errors; nothing is stored when there are any.
   */
  public function replace(string $name, array $record): array {
    return $this->store($name, $record, ChannelRules::replacementErrors(...));
  }

  public function delete(string $name): void {
    $all = $this->all();
    unset($all[$name]);
    $this->settings->set(self::SETTING, $all);
  }

  /**
   * @param array<array-key, mixed> $record
   * @param \Closure(string, string, array<array-key, mixed>, array<string, array{type: string}>): list<string> $rules
   * @return list<string>
   */
  private function store(string $name, array $record, \Closure $rules): array {
    $all = $this->all();
    $type = Cast::toString($record['type'] ?? '');
    $errors = $rules($name, $type, $record, $all);
    if (!$this->registry->has($type)) {
      return array_merge($errors, [E::ts('The channel type "%1" is not registered.', [1 => $type])]);
    }
    [$config, $configErrors] = StoredConfig::sealed(
      $type,
      $this->registry->get($type)->getConfigSchema(),
      ChannelRecord::config($record),
      $all[$name]['config'] ?? [],
    );
    $errors = array_merge($errors, $configErrors);
    if ($errors !== []) {
      return $errors;
    }
    $all[$name] = ChannelRecord::stored($name, $type, $record, $config);
    $this->settings->set(self::SETTING, $all);
    return [];
  }

  /**
   * @return array{type: string, label: string, active: bool, subscribable: bool, config: array<string, mixed>}
   */
  private function record(string $name): array {
    $record = $this->all()[$name] ?? NULL;
    if ($record === NULL) {
      throw new \CRM_Core_Exception(sprintf('herald: there is no channel "%s".', $name));
    }
    return $record;
  }

}
