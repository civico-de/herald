<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The secret keys of a channel config as CRED tokens: encrypted on save,
 * decrypted only for a send, shown to admin screens only as `set`.
 */
final class ChannelSecrets {

  /**
   * @param array<string, array<string, mixed>> $schema
   * @param array<string, mixed> $config
   * @return array<string, mixed>
   */
  public static function revealed(array $schema, array $config): array {
    foreach ($config as $key => $value) {
      if (empty($schema[$key]['secret']) || !is_string($value)) {
        continue;
      }
      $plain = self::crypto()->decrypt($value, '*');
      $config[$key] = ($schema[$key]['type'] ?? '') === 'object'
        ? json_decode($plain, TRUE, 512, JSON_THROW_ON_ERROR)
        : $plain;
    }
    return $config;
  }

  /**
   * @param array<string, array<string, mixed>> $schema
   * @param array<string, mixed> $config
   * @return array<string, mixed>
   */
  public static function masked(array $schema, array $config): array {
    foreach (array_keys($config) as $key) {
      if (empty($schema[$key]['secret'])) {
        continue;
      }
      $config[$key] = ['set' => TRUE];
    }
    return $config;
  }

  public static function seal(#[\SensitiveParameter] mixed $value): string {
    $plain = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : Cast::toString($value);
    return self::crypto()->encrypt($plain, 'CRED');
  }

  private static function crypto(): \Civi\Crypto\CryptoToken {
    /** @var \Civi\Crypto\CryptoToken */
    return \Civi::service('crypto.token');
  }

}
