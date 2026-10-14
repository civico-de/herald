<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Where the values of a digest come from. `name` is the source's name in the
 * template ({$name.…}); `type` decides the field it needs: search
 * `search_display_id`, section `section_key` (hook_civicrm_heraldDigestSections).
 */
return [
  'name' => 'HeraldDigestSource',
  'table' => 'civicrm_herald_digest_source',
  'class' => 'CRM_Herald_DAO_HeraldDigestSource',
  'getInfo' => fn () => [
    'title' => E::ts('Herald Digest Source'),
    'title_plural' => E::ts('Herald Digest Sources'),
    'description' => E::ts('A search display or extension section whose values fill a digest template.'),
    'log' => FALSE,
    'label_field' => 'name',
  ],
  'getIndices' => fn () => [
    'UI_digest_name' => [
      'fields' => ['digest_id' => TRUE, 'name' => TRUE],
      'unique' => TRUE,
    ],
  ],
  'getFields' => fn () => [
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
    'name' => [
      'title' => E::ts('Name'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Name of the source in the template, e.g. donations for {$donations.sum}.'),
    ],
    'type' => [
      'title' => E::ts('Source Type'),
      'sql_type' => 'varchar(16)',
      'input_type' => 'Select',
      'required' => TRUE,
      'default' => 'search',
      'pseudoconstant' => ['callback' => [\Civi\Herald\Digest\SourceType::class, 'options']],
    ],
    'search_display_id' => [
      'title' => E::ts('Search Display'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('Its column headers name the values, its filters and limit pick the rows.'),
      'input_attrs' => [
        'filter' => ['type' => \Civi\Herald\Digest\SearchSource::TYPES],
      ],
      'entity_reference' => [
        'entity' => 'SearchDisplay',
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
  ],
];
