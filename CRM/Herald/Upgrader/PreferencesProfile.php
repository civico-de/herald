<?php

declare(strict_types = 1);

use Civi\Api4\UFGroup;

/**
 * The self-service profile `herald_my_preferences` of earlier releases; the
 * "My notifications" form replaces it.
 */
final class CRM_Herald_Upgrader_PreferencesProfile {

  public const NAME = 'herald_my_preferences';

  public static function remove(): void {
    UFGroup::delete(checkPermissions: FALSE)->addWhere('name', '=', self::NAME)->execute();
  }

}
