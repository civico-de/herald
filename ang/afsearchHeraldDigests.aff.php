<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Herald digests'),
  'icon' => 'fa-newspaper-o',
  'server_route' => 'civicrm/admin/herald/digests',
  'permission' => ['administer CiviCRM'],
];
