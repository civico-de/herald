<?php

declare(strict_types = 1);

use Civi\Herald\Monitor\Monitor;

/**
 * Job.herald_monitor
 *
 * Runs the generic check monitor (see Civi\Herald\Monitor\Monitor): collects
 * checks contributed via hook_civicrm_heraldChecks, publishes ok↔fail
 * transitions to the herald_monitor_topic topic. Scheduled "Always" via
 * managed/Job.mgd.php; a run with no registered checks is a cheap no-op.
 *
 * @param array $spec
 */
function _civicrm_api3_job_herald_monitor_spec(&$spec) {
}

/**
 * @param array $params
 * @return array
 */
function civicrm_api3_job_herald_monitor($params) {
  return civicrm_api3_create_success(
    [(new Monitor())->run()],
    $params,
    'Job',
    'herald_monitor',
  );
}
