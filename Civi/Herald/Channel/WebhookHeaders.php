<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The request headers of a webhook: webhook_headers, a JSON object or array of
 * names and values, plus the bearer token unless they set Authorization.
 */
final class WebhookHeaders {

  /**
   * @return array<string, string>
   */
  public static function of(mixed $raw, #[\SensitiveParameter] string $token): array {
    // An empty form field stores "".
    if (is_string($raw)) {
      $raw = trim($raw) === '' ? [] : json_decode($raw, TRUE);
    }
    $headers = [];
    foreach (is_array($raw) ? $raw : [$raw] as $name => $value) {
      if (!is_string($name) || $name === '' || !is_scalar($value)) {
        throw new \CRM_Core_Exception('herald: webhook_headers must map header names to values.');
      }
      $headers[$name] = Cast::toString($value);
    }
    $named = array_change_key_case($headers);
    return $token === '' || isset($named['authorization'])
      ? $headers
      : $headers + ['Authorization' => "Bearer {$token}"];
  }

}
