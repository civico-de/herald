<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Request options that pin a webhook connection to the addresses the guard
 * validated, so a second DNS lookup at connect time cannot reach an internal host.
 */
final class WebhookPin {

  /**
   * Empty when the guard returned no addresses (allowlist mode).
   *
   * @param list<string> $ips
   * @return array{curl?: array<int, list<string>>}
   */
  public static function options(string $url, array $ips): array {
    if ($ips === []) {
      return [];
    }
    if (!extension_loaded('curl')) {
      throw new \CRM_Core_Exception(
        'herald: the curl extension is required to deliver webhooks safely (address pinning).',
      );
    }
    $parts = parse_url($url) ?: [];
    $host = trim(Cast::toString($parts['host'] ?? ''), '[]');
    $port = (int) ($parts['port'] ?? (strtolower(Cast::toString($parts['scheme'] ?? '')) === 'https' ? 443 : 80));
    return ['curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, implode(',', $ips))]]];
  }

}
