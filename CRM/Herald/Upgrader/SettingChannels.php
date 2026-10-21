<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Channel\PreferredChannelOptions;

/**
 * The channel settings of each domain become channels named like their type.
 */
final class CRM_Herald_Upgrader_SettingChannels {

  /**
   * Types a contact could pick before, and which were sent without credentials.
   */
  private const SUBSCRIBABLE = ['telegram', 'webhook', 'ntfy', 'pushover', 'email'];

  private const ALWAYS = ['webhook', 'ntfy', 'email', 'activity'];

  /**
   * A fresh install starts with the email channel group members fall back to, in every domain.
   */
  public static function ensureEmail(): void {
    foreach (CRM_Herald_Upgrader_Domain::ids() as $domainId) {
      self::save(new ChannelStore(NULL, Civi::settings($domainId)), 'email', 'email', [], TRUE);
    }
    PreferredChannelOptions::sync();
  }

  /**
   * @param array<string, mixed> $values
   */
  public static function create(int $domainId, array $values): int {
    $configs = array_fill_keys(self::ALWAYS, []);
    foreach ($values as $setting => $value) {
      [$type, $key] = CRM_Herald_Upgrader_OldSettings::MAP[$setting];
      // civicrm.settings.php may set a chat ID as a number.
      $value = is_int($value) ? (string) $value : $value;
      if (is_string($value) && trim($value) !== '') {
        $configs[$type][$key] = trim($value);
      }
    }
    // The default token only ever went with the default URL.
    if (empty($configs['webhook']['webhook_url'])) {
      unset($configs['webhook']['webhook_auth_token']);
    }
    // Without their token these types could not send before either.
    $configs = array_filter(
      $configs,
      static fn (array $config, string $type): bool => match ($type) {
        'telegram' => !empty($config['bot_token']),
        'pushover' => !empty($config['pushover_app_token']),
        default => TRUE,
      },
      ARRAY_FILTER_USE_BOTH,
    );
    $store = new ChannelStore(NULL, Civi::settings($domainId));
    $created = 0;
    foreach ($configs as $type => $config) {
      $created += self::save($store, $type, $type, $config, in_array($type, self::SUBSCRIBABLE, TRUE));
    }
    return $created;
  }

  /**
   * @param array<string, mixed> $config
   */
  private static function save(
    ChannelStore $store,
    string $name,
    string $type,
    array $config,
    bool $subscribable,
  ): int {
    if (isset($store->all()[$name])) {
      return 0;
    }
    $errors = $store->create($name, [
      'type' => $type,
      'label' => $name,
      'subscribable' => $subscribable,
      'config' => $config,
    ]);
    if ($errors !== []) {
      throw new CRM_Core_Exception(sprintf(
        'herald: the channel "%s" cannot be created: %s',
        $name,
        implode(' ', $errors),
      ));
    }
    return 1;
  }

}
