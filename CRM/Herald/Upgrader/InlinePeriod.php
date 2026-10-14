<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Digest and block periods in the parameters of older releases.
 */
final class CRM_Herald_Upgrader_InlinePeriod {

  public const KNOWN = ['yesterday', 'today', 'last_7_days', 'last_30_days'];

  /**
   * Older releases read every period they did not know as yesterday.
   */
  public static function read(mixed $value, string $digestName): string {
    $period = Cast::toString($value ?? 'yesterday');
    if (in_array($period, self::KNOWN, TRUE)) {
      return $period;
    }
    if ($period !== '') {
      \Civi::log('herald')->warning('herald: unknown digest period read as yesterday, as before', [
        'digest' => $digestName,
        'period' => $period,
      ]);
    }
    return 'yesterday';
  }

  /**
   * A period as an APIv4 where clause; core has no term for the days before
   * today without today.
   *
   * @return array{0: string, 1: string, 2: mixed}
   */
  public static function where(string $field, string $period): array {
    return match ($period) {
      'today' => [$field, '=', 'this.day'],
      'last_7_days' => ["DATEDIFF(CURDATE(), {$field})", 'BETWEEN', [1, 7]],
      'last_30_days' => ["DATEDIFF(CURDATE(), {$field})", 'BETWEEN', [1, 30]],
      default => [$field, '=', 'previous.day'],
    };
  }

}
