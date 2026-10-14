<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * The addresses a webhook host resolves to; a failed lookup throws.
 */
final class WebhookHost {

  /**
   * @param null|callable(string): list<string> $resolver
   * @return list<string>
   */
  public static function addresses(string $host, ?callable $resolver): array {
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return [$host];
    }

    try {
      return $resolver !== NULL ? $resolver($host) : self::dns($host);
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception(
        'herald: DNS lookup failed for webhook host ' . $host . '.',
        0,
        [],
        $e,
      );
    }
  }

  /**
   * Resolve both address families in one DNS query.
   *
   * PHP reports resolver failures as warnings and FALSE. Convert only warnings
   * raised during this one DNS call into an exception so the SSRF guard fails
   * closed, then immediately restore the previous application error handler.
   *
   * @param null|callable(string, int): (array<int, array<string, mixed>>|false) $lookup
   *   dns_get_record() unless given; tests pass a stub instead of real DNS.
   * @return list<string>
   */
  public static function dns(string $host, ?callable $lookup = NULL): array {
    $lookup ??= dns_get_record(...);
    set_error_handler(
      static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
      },
      E_WARNING,
    );
    try {
      $records = $lookup($host, DNS_A | DNS_AAAA);
    }
    finally {
      restore_error_handler();
    }

    if ($records === FALSE) {
      throw new \RuntimeException('DNS lookup returned false.');
    }

    $ips = [];
    foreach ($records as $record) {
      foreach (['ip', 'ipv6'] as $field) {
        $ip = $record[$field] ?? NULL;
        if (is_string($ip) && $ip !== '') {
          $ips[] = $ip;
        }
      }
    }

    return array_values(array_unique($ips));
  }

}
