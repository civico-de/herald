<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Addresses a webhook must not reach: private, reserved, loopback, link-local
 * (cloud metadata) and anything that is no address at all.
 */
final class BlockedAddress {

  /**
   * filter_var's private/reserved flags miss some of these in some PHP builds.
   */
  private const RANGES = ['0.0.0.0/8', '169.254.0.0/16', '::/128', '::1/128', 'fc00::/7', 'fe80::/10'];

  public static function is(string $ip): bool {
    // An IPv4-mapped IPv6 address is checked as the IPv4 address it carries.
    if (stripos($ip, '::ffff:') === 0 && substr_count($ip, '.') === 3) {
      $ip = substr($ip, (int) strrpos($ip, ':') + 1);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE) {
      return TRUE;
    }
    $packed = (string) inet_pton($ip);
    foreach (self::RANGES as $range) {
      [$network, $bits] = explode('/', $range);
      if (self::prefix($packed, (int) $bits) === self::prefix((string) inet_pton($network), (int) $bits)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The first $bits of a packed address; IPv4 and IPv6 never compare equal.
   */
  private static function prefix(string $packed, int $bits): string {
    $whole = intdiv($bits, 8);
    $rest = $bits % 8;
    $partial = $rest === 0 ? '' : chr(ord($packed[$whole] ?? "\0") & ((0xFF << (8 - $rest)) & 0xFF));
    return strlen($packed) . ':' . substr($packed, 0, $whole) . $partial;
  }

}
