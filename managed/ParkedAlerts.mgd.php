<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Admin list of parked alerts. Bodies and delivery params can carry contact
 * data, so the search selects neither; Herald.reactivateParkedAlerts sends rows back.
 */
return [
  [
    'name' => 'SavedSearch_Herald_Parked_Alerts',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Herald_Parked_Alerts',
        'label' => E::ts('Herald parked alerts'),
        'api_entity' => 'HeraldBufferedAlert',
        'api_params' => [
          'version' => 4,
          'select' => ['id', 'group_hash', 'created_date', 'parked_at', 'park_reason', 'attempts', 'last_error'],
          'where' => [['parked_at', 'IS NOT NULL']],
          'orderBy' => ['parked_at' => 'DESC', 'id' => 'DESC'],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_Herald_Parked_Alerts_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Herald_Parked_Alerts_Table',
        'label' => E::ts('Herald parked alerts'),
        'saved_search_id.name' => 'Herald_Parked_Alerts',
        'type' => 'table',
        'settings' => [
          'actions' => FALSE,
          'limit' => 50,
          'pager' => ['show_count' => TRUE, 'expose_limit' => TRUE],
          'sort' => [['parked_at', 'DESC'], ['id', 'DESC']],
          'placeholder' => 5,
          'noResultsText' => E::ts('No parked alerts.'),
          'columns' => [
            ['type' => 'field', 'key' => 'id', 'label' => E::ts('ID'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'parked_at', 'label' => E::ts('Parked'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'park_reason', 'label' => E::ts('Park Reason'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'attempts', 'label' => E::ts('Attempts'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'last_error', 'label' => E::ts('Last Error'), 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'created_date', 'label' => E::ts('Buffered'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'group_hash', 'label' => E::ts('Group'), 'sortable' => TRUE],
          ],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'Navigation_Herald_Parked_Alerts',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'herald_parked_alerts',
        'label' => E::ts('Herald parked alerts'),
        'url' => 'civicrm/search#/display/Herald_Parked_Alerts/Herald_Parked_Alerts_Table',
        'permission' => 'administer CiviCRM',
        'parent_id.name' => 'System Settings',
        'is_active' => TRUE,
        'domain_id' => 'current_domain',
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];
