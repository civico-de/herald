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
 * A run in which a publish failed returns an error.
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
  $result = (new Monitor())->run();
  $failures = $result['publish_failures'] ?? 0;
  if ($failures > 0) {
    // Marks the scheduled-job run failed; the checks retry on the next run.
    return civicrm_api3_create_error(
      "herald monitor: {$failures} transition(s) could not be published, see the CiviCRM log",
      ['values' => [$result]],
    );
  }
  return civicrm_api3_create_success(
    [$result],
    $params,
    'Job',
    'herald_monitor',
  );
}
