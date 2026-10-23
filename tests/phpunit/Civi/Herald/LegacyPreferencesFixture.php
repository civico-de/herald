<?php

declare(strict_types = 1);

namespace Civi\Herald;

use Civi\Herald\Subscribers\LegacyPreferences;

/**
 * The one preference record per contact of an older release, for tests of the
 * upgrade steps that read or move it. Not transactional: it adds a table.
 */
final class LegacyPreferencesFixture {

  /**
   * The group as the reconcile of an older release left it.
   */
  public static function install(): void {
    \Civi\Api4\CustomGroup::create(checkPermissions: FALSE)
      ->setValues(['name' => LegacyPreferences::GROUP, 'title' => 'Notification preferences', 'extends' => 'Contact'])
      ->execute();
    \CRM_Herald_Upgrader_Reconcile::run();
  }

  /**
   * Drop the group and its table if still there, and let the reconcile forget it.
   */
  public static function remove(): void {
    \Civi\Api4\CustomGroup::delete(checkPermissions: FALSE)
      ->addWhere('name', '=', LegacyPreferences::GROUP)
      ->execute();
    \CRM_Herald_Upgrader_Reconcile::run();
  }

}
