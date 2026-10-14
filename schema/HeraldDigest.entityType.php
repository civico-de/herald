<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * A digest: one message template filled from its sources (HeraldDigestSource).
 * Rules and Herald.notifyDigest name it by `name`.
 */
return [
  'name' => 'HeraldDigest',
  'table' => 'civicrm_herald_digest',
  'class' => 'CRM_Herald_DAO_HeraldDigest',
  'getInfo' => fn () => [
    'title' => E::ts('Herald Digest'),
    'title_plural' => E::ts('Herald Digests'),
    'description' => E::ts('A summary message: one message template filled from searches and extension sections.'),
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
    // Required on save (DigestRules); a deleted template leaves the digest to fail at send.
    'message_template_id' => [
      'title' => E::ts('Message Template'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('Subject and text of the digest, with the values of its sources as placeholders.'),
      // User-driven templates only; autocomplete drops a NULL `filter`.
      'input_attrs' => [
        'where' => [['workflow_name', 'IS NULL']],
      ],
      'entity_reference' => [
        'entity' => 'MessageTemplate',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
  ],
];
