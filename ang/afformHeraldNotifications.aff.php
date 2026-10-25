<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

return [
  'type' => 'form',
  'title' => E::ts('Notifications'),
  'icon' => 'fa-bell',
  'placement' => ['contact_summary_tab'],
  'permission' => ['access CiviCRM'],
];
