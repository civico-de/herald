<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Admin list of parked alerts. Bodies and delivery params can carry contact
 * data, so the search selects neither. The reactivate task sends selected rows back
 * (HeraldBufferedAlert.reactivate); the menu item sits in Navigation.mgd.php.
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
          'actions' => ['herald_reactivate'],
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
];
