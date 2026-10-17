<?php

declare(strict_types = 1);

namespace Civi\Herald;

/**
 * The headless site most tests share; every other step list reinstalls the
 * test database, once on the way in and once on the way back.
 */
final class HeadlessSite {

  public static function standard(): \Civi\Test\CiviEnvBuilder {
    return ck_headless('org.civicoop.civirules')
      // APIv4 hides Contribution.campaign_id while CiviCampaign is off; enabling it commits.
      ->callback(static fn () => \CRM_Core_BAO_ConfigSetting::enableComponent('CiviCampaign'), 'herald-civicampaign');
  }

}
