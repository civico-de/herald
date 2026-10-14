<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

return [
  'type' => 'form',
  'title' => E::ts('Herald digest'),
  'icon' => 'fa-newspaper-o',
  'server_route' => 'civicrm/admin/herald/digest',
  'permission' => ['administer CiviCRM'],
  'redirect' => 'civicrm/admin/herald/digests',
];
