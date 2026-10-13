<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use CRM_Herald_ExtensionUtil as E;

/**
 * Checks herald_webhook_allowed_hosts entries: bare host names or IP
 * addresses, since WebhookChannel compares them with the URL's host only.
 */
final class AllowedHosts {

  public const SETTING = 'herald_webhook_allowed_hosts';

  /**
   * Blank entries are skipped; every other entry is stored trimmed.
   *
   * @param array<array-key, mixed> $hosts
   * @return list<string>
   * @throws InvalidInput
   */
  public static function clean(array $hosts): array {
    $clean = [];
    $errors = [];
    foreach ($hosts as $host) {
      $host = is_string($host) ? trim($host) : NULL;
      if ($host === '') {
        continue;
      }
      $problem = $host === NULL ? E::ts('Each host must be text.') : self::problem($host);
      if ($problem !== NULL) {
        $errors[] = $problem;
        continue;
      }
      $clean[] = (string) $host;
    }
    InvalidInput::throwIfAny($errors);
    return $clean;
  }

  private static function problem(string $host): ?string {
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return NULL;
    }
    return match (TRUE) {
      str_contains($host, '://') => E::ts('"%1": enter only the host name, without "https://".', [1 => $host]),
      str_contains($host, '/') => E::ts('"%1": enter only the host name, without a path.', [1 => $host]),
      preg_match('/:\d*$/', $host) === 1 => E::ts('"%1": enter the host name without a port.', [1 => $host]),
      filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === FALSE
        => E::ts('"%1" is not a valid host name.', [1 => $host]),
      default => NULL,
    };
  }

}
