<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * The config ChannelStore keeps for a channel: known keys only, blanks
 * dropped, secrets encrypted, a secret left out kept unless it is NULL.
 */
final class StoredConfig {

  /**
   * @param array<string, array<string, mixed>> $schema
   * @param array<string, mixed> $config
   * @param array<string, mixed> $previous
   * @return array{array<string, mixed>, list<string>}
   */
  public static function sealed(string $type, array $schema, array $config, array $previous): array {
    $errors = [];
    $out = [];
    foreach ($config as $key => $value) {
      // The mask getChannels shows for a stored secret keeps it.
      if (!empty($schema[$key]['secret']) && $value === ['set' => TRUE]) {
        unset($config[$key]);
        continue;
      }
      $error = ConfigSpec::valueError($type, $key, $schema[$key] ?? NULL, $value);
      if ($error !== NULL) {
        $errors[] = $error;
        continue;
      }
      if (ConfigSpec::blank($value)) {
        continue;
      }
      $out[$key] = empty($schema[$key]['secret']) ? $value : ChannelSecrets::seal($value);
    }
    $out += self::keptSecrets($schema, $config, $previous, $out);
    return [$out, array_merge($errors, ConfigSpec::missing($schema, $out), BoundKeys::errors($schema, $out))];
  }

  /**
   * The stored secrets $config neither sets nor clears with NULL, in schema order.
   *
   * @param array<string, array<string, mixed>> $schema
   * @param array<string, mixed> $config
   * @param array<string, mixed> $previous
   * @param array<string, mixed> $out
   * @return array<string, mixed>
   */
  private static function keptSecrets(array $schema, array $config, array $previous, array $out): array {
    $kept = [];
    foreach ($schema as $key => $spec) {
      $cleared = array_key_exists($key, $config) && $config[$key] === NULL;
      if (empty($spec['secret']) || $cleared || array_key_exists($key, $out) || !isset($previous[$key])) {
        continue;
      }
      $kept[$key] = $previous[$key];
    }
    return $kept;
  }

}
