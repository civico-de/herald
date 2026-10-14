<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * SSRF guard for webhook URLs: only public http(s) endpoints, or the hosts an
 * admin allowed.
 */
final class WebhookUrlGuard {

  /**
   * Rejects a URL whose scheme is not http/https, that carries credentials,
   * or that resolves to a private, reserved, link-local or metadata address.
   * A non-empty allowed_hosts list locks delivery to those hosts instead.
   *
   * @param array{allowed_hosts?: array<int|string, mixed>, resolver?: null|callable(string): list<string>} $opts
   */
  public static function assert(string $url, array $opts = []): void {
    $parts = parse_url($url) ?: [];
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = trim($parts['host'] ?? '', '[]');
    if ($scheme === '' || $host === '') {
      throw new \CRM_Core_Exception('herald: webhook URL is not a valid absolute URL.');
    }
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      throw new \CRM_Core_Exception('herald: webhook URL must use http or https, got ' . $scheme . '.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      throw new \CRM_Core_Exception('herald: webhook URL must not contain credentials.');
    }
    $allowed = $opts['allowed_hosts'] ?? [];
    if ($allowed !== []) {
      if (!self::listed(strtolower($host), $allowed)) {
        throw new \CRM_Core_Exception('herald: webhook host is not in herald_webhook_allowed_hosts.');
      }
      return;
    }

    $ips = WebhookHost::addresses($host, $opts['resolver'] ?? NULL);
    if ($ips === []) {
      throw new \CRM_Core_Exception('herald: could not resolve webhook host ' . $host . '.');
    }
    if (array_filter($ips, BlockedAddress::is(...)) !== []) {
      throw new \CRM_Core_Exception('herald: webhook host resolves to a disallowed address.');
    }
  }

  /**
   * A host matches an entry or is a subdomain of one; blank entries match nothing.
   *
   * @param array<int|string, mixed> $allowed
   */
  private static function listed(string $host, array $allowed): bool {
    $entries = array_filter(array_map(static fn (mixed $entry): string => strtolower(trim(Cast::toString(
      $entry,
    ))), $allowed));
    return (
      array_filter($entries, static fn (string $entry): bool => $host === $entry || str_ends_with($host, ".{$entry}"))
      !== []
    );
  }

}
