<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Core\Locale;
use Civi\Herald\Api4\Rows;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Core's status messages, and the site-language scope the monitor reads and
 * translates them in, whatever language the process runs in.
 */
final class SystemCheck {

  /**
   * @return list<array<array-key, mixed>>
   */
  public static function messages(): array {
    return Rows::of(\Civi\Api4\System::check(FALSE)->execute());
  }

  /**
   * @template T
   * @param \Closure(): T $work
   * @return T
   * @throws \CRM_Core_Exception The site language is malformed.
   */
  public static function inSiteLocale(\Closure $work): mixed {
    $previous = Locale::detect();
    Locale::negotiate(Cast::toString(\Civi::settings()->get('lcMessages')))->apply();
    try {
      return $work();
    }
    finally {
      $previous->apply();
    }
  }

}
