<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * A digest: a subject and text filled from its sources (HeraldDigestSource).
 * Rules and Herald.notifyDigest name it by `name`.
 */
return [
  'name' => 'HeraldDigest',
  'table' => 'civicrm_herald_digest',
  'class' => 'CRM_Herald_DAO_HeraldDigest',
  'getInfo' => fn () => [
    'title' => E::ts('Herald Digest'),
    'title_plural' => E::ts('Herald Digests'),
    'description' => E::ts('A summary message: subject and text filled from searches and extension sections.'),
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
    // Named like MessageTemplate's fields: APIv4 stores these verbatim instead of encoding < and >.
    'msg_subject' => [
      'title' => E::ts('Subject'),
      'sql_type' => 'text',
      'input_type' => 'Text',
      'description' => E::ts('Placeholders work here too. Left empty, the title is the subject.'),
    ],
    // Required on save (DigestRules).
    'msg_html' => [
      'title' => E::ts('Text'),
      'sql_type' => 'longtext',
      'input_type' => 'TextArea',
      'description' => E::ts('HTML and Smarty, with the values of the sources as placeholders.'),
      'input_attrs' => [
        'rows' => 20,
      ],
    ],
  ],
];
