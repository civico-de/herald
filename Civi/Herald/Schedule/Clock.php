<?php

declare(strict_types = 1);

namespace Civi\Herald\Schedule;

/**
 * CiviCRM's wall clock: the CMS timezone, and CRM_Utils_Time so tests can move it.
 */
final class Clock {

  public static function zone(): \DateTimeZone {
    $zone = \CRM_Core_Config::singleton()->userSystem->getTimeZoneString() ?: date_default_timezone_get();
    return new \DateTimeZone($zone);
  }

  public static function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . \CRM_Utils_Time::time()))->setTimezone(self::zone());
  }

}
