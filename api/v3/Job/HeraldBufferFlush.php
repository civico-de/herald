<?php

declare(strict_types = 1);

use Civi\Herald\Buffer\AlertBuffer;

/**
 * Job.herald_buffer_flush
 *
 * Drains the alert aggregation buffer (see Civi\Herald\Buffer\AlertBuffer):
 * every group whose oldest entry has waited at least its configured
 * aggregate_minutes window goes out as one composed message. Scheduled with
 * run_frequency "Always" via managed/Job.mgd.php so flushes happen on every
 * cron run; rows are only deleted after a successful send, so a channel
 * failure retries on the next run.
 *
 * @param array $spec
 */
function _civicrm_api3_job_herald_buffer_flush_spec(&$spec) {
}

/**
 * @param array $params
 * @return array
 */
function civicrm_api3_job_herald_buffer_flush($params) {
  $buffer = new AlertBuffer();
  $sent = 0;
  $failed = 0;
  foreach ($buffer->claimDue() as $group) {
    try {
      $body = AlertBuffer::compose($group['bodies'], $group['window'], $group['amounts']);
      if ($body !== '') {
        CRM_Herald_CivirulesActions_AbstractSend::deliver($body, $group['delivery']);
      }
      $buffer->deleteRows($group['ids']);
      $sent++;
    }
    catch (\Throwable $e) {
      $failed++;
      \Civi::log()->warning('herald: buffer flush failed for group, will retry next run', [
        'group_hash' => $group['group_hash'],
        'count' => count($group['ids']),
        'error' => $e->getMessage(),
      ]);
    }
  }
  return civicrm_api3_create_success(
    [['sent' => $sent, 'failed' => $failed]],
    $params,
    'Job',
    'herald_buffer_flush',
  );
}
