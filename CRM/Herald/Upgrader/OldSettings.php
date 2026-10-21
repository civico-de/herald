<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The per-type channel settings of releases before named channels.
 */
final class CRM_Herald_Upgrader_OldSettings {

  /**
   * Old setting => [type, key] of the channel it moves into.
   */
  public const MAP = [
    'herald_telegram_bot_token' => ['telegram', 'bot_token'],
    'herald_telegram_default_chat_id' => ['telegram', 'chat_id'],
    'herald_webhook_default_url' => ['webhook', 'webhook_url'],
    'herald_webhook_default_auth_token' => ['webhook', 'webhook_auth_token'],
    'herald_ntfy_server' => ['ntfy', 'ntfy_server'],
    'herald_ntfy_default_topic' => ['ntfy', 'ntfy_topic'],
    'herald_ntfy_token' => ['ntfy', 'ntfy_token'],
    'herald_pushover_app_token' => ['pushover', 'pushover_app_token'],
    'herald_pushover_default_user_key' => ['pushover', 'pushover_user_key'],
    'herald_email_from' => ['email', 'email_from'],
    'herald_email_default_to' => ['email', 'email_to'],
  ];

  public static function deleteOld(): void {
    CRM_Core_DAO::executeQuery(
      'DELETE FROM civicrm_setting WHERE name IN ('
      . implode(', ', array_map(
        static fn (string $name): string => "'{$name}'",
        array_keys(self::MAP),
      ))
      . ')',
    );
  }

  /**
   * @return array<int, array<string, mixed>> domain id => old setting => value
   */
  public static function read(): array {
    /** @var list<array{domain_id: mixed, name: string, value: mixed}> $rows */
    $rows = CRM_Utils_SQL_Select::from('civicrm_setting')
      ->select(['domain_id', 'name', 'value'])
      ->where('name IN (@names) AND domain_id IS NOT NULL', ['names' => array_keys(self::MAP)])
      ->execute()
      ->fetchAll();
    $out = array_fill_keys(CRM_Herald_Upgrader_Domain::ids(), []);
    foreach ($rows as $row) {
      $value = is_string($row['value']) ? CRM_Utils_String::unserialize($row['value']) : NULL;
      $out[Cast::toInt($row['domain_id'])][$row['name']] = $value;
    }
    // A value from civicrm.settings.php ($civicrm_setting) beats the stored one.
    foreach ($out as $domainId => $values) {
      $mandatory = array_map(
        static fn (string $name): mixed => Civi::settings($domainId)->getMandatory($name),
        array_combine(array_keys(self::MAP), array_keys(self::MAP)),
      );
      $out[$domainId] = array_filter($mandatory, static fn (mixed $value): bool => $value !== NULL) + $values;
    }
    return $out;
  }

}
