<?php

declare(strict_types = 1);

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
        'description' => E::ts(
          'Sends alerts collected via aggregate_minutes as one composed message per rule once their window has passed.',
        ),
        'run_frequency' => 'Always',
        'api_entity' => 'Job',
        'api_action' => 'herald_buffer_flush',
        'parameters' => '',
        'is_active' => TRUE,
      ],
      'match' => ['api_entity', 'api_action'],
    ],
  ],
  [
    'name' => 'herald_job_monitor',
    'entity' => 'Job',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => E::ts('Herald: Run check monitor'),
        'description' => E::ts(
          'Runs checks contributed via hook_civicrm_heraldChecks and publishes ok/fail transitions to the herald_monitor_topic topic.',
        ),
        'run_frequency' => 'Always',
        'api_entity' => 'Job',
        'api_action' => 'herald_monitor',
        'parameters' => '',
        'is_active' => TRUE,
      ],
      'match' => ['api_entity', 'api_action'],
    ],
  ],
];
