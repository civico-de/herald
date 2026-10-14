<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Admin list of digests (afsearchHeraldDigests); add and edit open the form
 * afformHeraldDigest through the paths of schema/HeraldDigest.entityType.php.
 */
return [
  [
    'name' => 'SavedSearch_Herald_Digests',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Herald_Digests',
        'label' => E::ts('Herald digests'),
        'api_entity' => 'HeraldDigest',
        'api_params' => [
          'version' => 4,
          'select' => ['id', 'title', 'name', 'period:label'],
          'orderBy' => ['title' => 'ASC'],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_Herald_Digests_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Herald_Digests_Table',
        'label' => E::ts('Herald digests'),
        'saved_search_id.name' => 'Herald_Digests',
        'type' => 'table',
        'settings' => [
          'limit' => 50,
          'pager' => ['show_count' => TRUE],
          'sort' => [['title', 'ASC']],
          'placeholder' => 5,
          'noResultsText' => E::ts('No digests yet.'),
          'toolbar' => [
            [
              'entity' => 'HeraldDigest',
              'action' => 'add',
              'style' => 'primary',
              'text' => E::ts('Add digest'),
              'icon' => 'fa-plus',
            ],
          ],
          'columns' => [
            [
              'type' => 'field',
              'key' => 'title',
              'label' => E::ts('Title'),
              'sortable' => TRUE,
              'link' => ['entity' => 'HeraldDigest', 'action' => 'update'],
            ],
            ['type' => 'field', 'key' => 'name', 'label' => E::ts('Name'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'period:label', 'label' => E::ts('Period'), 'sortable' => TRUE],
            [
              'type' => 'buttons',
              'alignment' => 'text-right',
              'links' => [
                [
                  'entity' => 'HeraldDigest',
                  'action' => 'update',
                  'icon' => 'fa-pencil',
                  'text' => E::ts('Edit'),
                  'style' => 'default',
                ],
                [
                  'entity' => 'HeraldDigest',
                  'task' => 'delete',
                  'icon' => 'fa-trash',
                  'text' => E::ts('Delete'),
                  'style' => 'danger',
                  'target' => 'crm-popup',
                ],
              ],
            ],
          ],
        ],
      ],
      'match' => ['name'],
    ],
  ],
];
