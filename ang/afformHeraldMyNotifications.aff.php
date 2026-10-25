<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

return [
  'type' => 'form',
  'title' => E::ts('My notifications'),
  'icon' => 'fa-bell',
  'server_route' => 'civicrm/herald/my-notifications',
  'permission' => ['access CiviCRM'],
];
