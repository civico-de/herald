<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

// A filter deleted with its campaign or financial type would widen the block
// to every contribution, so both block the deletion instead.
$contributionFields = fn () => [
  'campaign_id' => [
    'title' => E::ts('Campaign'),
    'sql_type' => 'int unsigned',
    'input_type' => 'EntityRef',
    'entity_reference' => [
      'entity' => 'Campaign',
      'key' => 'id',
      'on_delete' => 'RESTRICT',
    ],
  ],
  'financial_type_id' => [
    'title' => E::ts('Financial Type'),
    'sql_type' => 'int unsigned',
    'input_type' => 'Select',
    'pseudoconstant' => [
      'table' => 'civicrm_financial_type',
      'key_column' => 'id',
      'label_column' => 'label',
    ],
    'entity_reference' => [
      'entity' => 'FinancialType',
      'key' => 'id',
      'on_delete' => 'RESTRICT',
    ],
  ],
  'source_pattern' => [
    'title' => E::ts('Source Pattern'),
    'sql_type' => 'varchar(255)',
    'input_type' => 'Text',
    'description' => E::ts('SQL LIKE pattern on the contribution source, e.g. %Bank TX%.'),
  ],
  'group_by' => [
    'title' => E::ts('Break Down By'),
    'sql_type' => 'varchar(16)',
    'input_type' => 'Select',
    'required' => TRUE,
    'default' => 'source',
    'pseudoconstant' => ['callback' => [\Civi\Herald\Digest\Options::class, 'groupings']],
  ],
  'show_pending' => [
    'title' => E::ts('Show Pending Pool'),
    'sql_type' => 'boolean',
    'input_type' => 'CheckBox',
    'required' => TRUE,
    'default' => TRUE,
  ],
  'show_histogram' => [
    'title' => E::ts('Show Histogram'),
    'sql_type' => 'boolean',
    'input_type' => 'CheckBox',
    'required' => TRUE,
    'default' => TRUE,
  ],
  'bucket_count' => [
    'title' => E::ts('Histogram Buckets'),
    'sql_type' => 'int unsigned',
    'input_type' => 'Number',
    'required' => TRUE,
    'default' => 8,
  ],
  'bucket_edges' => [
    'title' => E::ts('Histogram Bucket Edges'),
    'sql_type' => 'varchar(255)',
    'input_type' => 'Text',
    'description' => E::ts('JSON array of fixed edges, e.g. [0, 10, 50, 250]. Empty: adaptive buckets.'),
  ],
];

/**
 * One block of a digest, in id order. `type` decides which fields apply:
 * contribution the filter and histogram fields, search `saved_search_id`,
 * section `section_key` (hook_civicrm_heraldDigestSections).
 */
return [
  'name' => 'HeraldDigestBlock',
  'table' => 'civicrm_herald_digest_block',
  'class' => 'CRM_Herald_DAO_HeraldDigestBlock',
  'getInfo' => fn () => [
    'title' => E::ts('Herald Digest Block'),
    'title_plural' => E::ts('Herald Digest Blocks'),
    'description' => E::ts('A contribution summary, saved search or extension section within a digest.'),
    'log' => FALSE,
    'label_field' => 'title',
  ],
  'getFields' => fn () => (
    [
      'id' => [
        'title' => E::ts('ID'),
        'sql_type' => 'int unsigned',
        'input_type' => 'Number',
        'required' => TRUE,
        'primary_key' => TRUE,
        'auto_increment' => TRUE,
      ],
      'digest_id' => [
        'title' => E::ts('Digest'),
        'sql_type' => 'int unsigned',
        'input_type' => 'EntityRef',
        'required' => TRUE,
        'entity_reference' => [
          'entity' => 'HeraldDigest',
          'key' => 'id',
          'on_delete' => 'CASCADE',
        ],
      ],
      'type' => [
        'title' => E::ts('Block Type'),
        'sql_type' => 'varchar(16)',
        'input_type' => 'Select',
        'required' => TRUE,
        'default' => 'contribution',
        'pseudoconstant' => ['callback' => [\Civi\Herald\Digest\Options::class, 'blockTypes']],
      ],
      'title' => [
        'title' => E::ts('Title'),
        'sql_type' => 'varchar(255)',
        'input_type' => 'Text',
        'description' => E::ts('Heading of the block, as {$block_title} in its template.'),
      ],
      'period' => [
        'title' => E::ts('Period'),
        'sql_type' => 'varchar(32)',
        'input_type' => 'Select',
        'description' => E::ts('Empty: the period of the digest.'),
        'pseudoconstant' => ['callback' => [\Civi\Herald\Digest\Options::class, 'periods']],
      ],
      'template' => [
        'title' => E::ts('Message Template'),
        'sql_type' => 'varchar(255)',
        'input_type' => 'Select',
        'description' => E::ts('Workflow name of the block template. Empty: the default of the block type.'),
        'pseudoconstant' => ['callback' => [\Civi\Herald\Digest\Options::class, 'blockTemplates']],
      ],
    ]
    + $contributionFields()
    + [
      'saved_search_id' => [
        'title' => E::ts('Saved Search'),
        'sql_type' => 'int unsigned',
        'input_type' => 'EntityRef',
        'entity_reference' => [
          'entity' => 'SavedSearch',
          'key' => 'id',
          'on_delete' => 'SET NULL',
        ],
      ],
      'section_key' => [
        'title' => E::ts('Section Key'),
        'sql_type' => 'varchar(128)',
        'input_type' => 'Text',
        'description' => E::ts('Key an extension answers in hook_civicrm_heraldDigestSections, e.g. myext.seats.'),
      ],
    ]
  ),
];
