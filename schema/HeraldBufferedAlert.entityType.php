<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * One rendered alert waiting in the aggregation buffer, or parked as a dead
 * letter. Written and drained by Civi\Herald\Buffer\AlertBuffer.
 *
 * `body` and `delivery` can carry contact data; list displays leave them out.
 */
return [
  'name' => 'HeraldBufferedAlert',
  'table' => 'civicrm_herald_buffer',
  'class' => 'CRM_Herald_DAO_HeraldBufferedAlert',
  'getInfo' => fn () => [
    'title' => E::ts('Herald Buffered Alert'),
    'title_plural' => E::ts('Herald Buffered Alerts'),
    'description' => E::ts('Alerts held for aggregated delivery, and parked alerts that could not be delivered.'),
    'log' => FALSE,
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
    'group_hash' => [
      'title' => E::ts('Group'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Hash of the sending rule action; alerts with the same hash are aggregated together.'),
    ],
    'window_minutes' => [
      'title' => E::ts('Window (minutes)'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'default' => 5,
    ],
    'amount' => [
      'title' => E::ts('Amount'),
      'sql_type' => 'decimal(20,2)',
      'input_type' => 'Text',
      'description' => E::ts('Monetary value of the underlying event, summed into the aggregate header.'),
    ],
    'body' => [
      'title' => E::ts('Body'),
      'sql_type' => 'longtext',
      'input_type' => 'TextArea',
      'required' => TRUE,
    ],
    'delivery' => [
      'title' => E::ts('Delivery Parameters'),
      'sql_type' => 'longtext',
      'input_type' => 'TextArea',
      'required' => TRUE,
      'description' => E::ts('JSON-encoded action parameters used to send the aggregated message.'),
    ],
    'created_date' => [
      'title' => E::ts('Buffered'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'required' => TRUE,
      'default' => 'CURRENT_TIMESTAMP',
    ],
    'claimed_by' => [
      'title' => E::ts('Claimed By'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'description' => E::ts('Token of the flush run currently delivering this alert.'),
    ],
    'claimed_at' => [
      'title' => E::ts('Claimed'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'default' => NULL,
    ],
    'attempts' => [
      'title' => E::ts('Attempts'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'default' => 0,
    ],
    'parked_at' => [
      'title' => E::ts('Parked'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'default' => NULL,
      'description' => E::ts('Set when the alert was moved to the dead letter; parked alerts are never flushed.'),
    ],
    'park_reason' => [
      'title' => E::ts('Park Reason'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
    ],
    'last_error' => [
      'title' => E::ts('Last Error'),
      'sql_type' => 'text',
      'input_type' => 'TextArea',
    ],
  ],
  'getIndices' => fn () => [
    'index_group_hash' => [
      'fields' => ['group_hash' => TRUE],
    ],
    'index_claimed_by' => [
      'fields' => ['claimed_by' => TRUE],
    ],
    'index_parked_at' => [
      'fields' => ['parked_at' => TRUE],
    ],
  ],
];
