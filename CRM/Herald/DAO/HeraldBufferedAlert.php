<?php

/**
 * DAOs provide an OOP-style facade for reading and writing database records.
 *
 * DAOs are a primary source for metadata in older versions of CiviCRM (<5.74)
 * and are required for some subsystems (such as APIv3).
 *
 * This stub provides compatibility. It is not intended to be modified in a
 * substantive way. Property annotations may be added, but are not required.
 *
 * @property string|null $id
 * @property string|null $group_hash
 * @property string|null $window_minutes
 * @property string|null $amount
 * @property string|null $body
 * @property string|null $delivery
 * @property string|null $claimed_by
 */
class CRM_Herald_DAO_HeraldBufferedAlert extends CRM_Herald_DAO_Base {

  /**
   * Required by older versions of CiviCRM (<5.74).
   * @var string
   */
  public static $_tableName = 'civicrm_herald_buffer';

}
