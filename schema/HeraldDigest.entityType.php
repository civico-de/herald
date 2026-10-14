<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * A digest: a header and the blocks (HeraldDigestBlock) composed into one
 * message. Rules and Herald.notifyDigest name it by `name`.
 */
return [
  'name' => 'HeraldDigest',
  'table' => 'civicrm_herald_digest',
  'class' => 'CRM_Herald_DAO_HeraldDigest',
  'getInfo' => fn () => [
    'title' => E::ts('Herald Digest'),
    'title_plural' => E::ts('Herald Digests'),
    'description' => E::ts('A summary message composed of contribution, search and section blocks.'),
    'log' => FALSE,
    'label_field' => 'title',
  ],
  'getPaths' => fn () => [
    'add' => 'civicrm/admin/herald/digest#?',
    'update' => 'civicrm/admin/herald/digest#?HeraldDigest1=[id]',
    'browse' => 'civicrm/admin/herald/digests',
  ],
  'getIndices' => fn () => [
    'UI_name' => [
      'fields' => ['name' => TRUE],
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
    'name' => [
      'title' => E::ts('Name'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Machine name, used by rules and scripts to pick the digest.'),
    ],
    'title' => [
      'title' => E::ts('Title'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
    ],
    'header' => [
      'title' => E::ts('Header'),
      'sql_type' => 'text',
      'input_type' => 'TextArea',
      'description' => E::ts('HTML line above the blocks.'),
    ],
    'period' => [
      'title' => E::ts('Period'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Select',
      'required' => TRUE,
      'default' => 'yesterday',
      'description' => E::ts('Period of every block that sets none of its own.'),
      'pseudoconstant' => ['callback' => [\Civi\Herald\Digest\Options::class, 'periods']],
    ],
  ],
];
