<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

return [
  [
    'name' => 'OptionValue__activity_type__herald_notification',
    'entity' => 'OptionValue',
    // Activities are audit/history records. Removing the extension must never
    // cascade-delete them through the activity-type OptionValue cleanup.
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'herald_notification',
        'label' => E::ts('Herald notification'),
        'is_active' => TRUE,
        'is_reserved' => TRUE,
      ],
      'match' => ['option_group_id', 'name'],
    ],
  ],
];
