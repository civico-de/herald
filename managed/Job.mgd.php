<?php

use CRM_Herald_ExtensionUtil as E;

/**
 * Scheduled job that drains the alert aggregation buffer on every cron run.
 * No-op (one cheap SELECT) while no rule uses aggregate_minutes.
 */
return [
  [
    'name' => 'herald_job_buffer_flush',
    'entity' => 'Job',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => E::ts('Herald: Flush alert aggregation buffer'),
        'description' => E::ts('Sends alerts collected via aggregate_minutes as one composed message per rule once their window has passed.'),
        'run_frequency' => 'Always',
        'api_entity' => 'Job',
        'api_action' => 'herald_buffer_flush',
        'parameters' => '',
        'is_active' => TRUE,
      ],
      'match' => ['api_entity', 'api_action'],
    ],
  ],
];
