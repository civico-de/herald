<?php

declare(strict_types = 1);

use Civi\Herald\Digest\Options;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Digest and block periods in the parameters of older releases.
 */
final class CRM_Herald_Upgrader_InlinePeriod {

  /**
   * Older releases read every period they did not know as yesterday.
   */
  public static function read(mixed $value, string $digestName): string {
    $period = Cast::toString($value ?? 'yesterday');
    if (array_key_exists($period, Options::periods())) {
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

}
