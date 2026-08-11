<?php

declare(strict_types = 1);

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Dispatch\Dispatcher;

/**
 * Job.herald_buffer_flush
 *
 * Drains the alert aggregation buffer (see Civi\Herald\Buffer\AlertBuffer):
 * every group whose oldest entry has waited at least its configured
 * aggregate_minutes window goes out as one composed message. Scheduled with
 * run_frequency "Always" via managed/Job.mgd.php so flushes happen on every
 * cron run; rows are only deleted after a successful send and handed back to
 * the buffer after a failure, so a channel failure retries on the next run.
 *
 * Concurrency: cron gives long jobs no lock of its own, so overlapping runs
 * are this job's problem. Two layers handle it — a named lock skips a run
 * while another holds it, and AlertBuffer::claimDue() stamps the rows it hands
 * out, which is what actually rules out delivering a group twice.
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
  $lock = \Civi::lockManager()->acquire(AlertBuffer::LOCK_NAME);
  if (!$lock->isAcquired()) {
    return civicrm_api3_create_success(
      [['sent' => 0, 'failed' => 0, 'skipped_locked' => TRUE]],
      $params,
      'Job',
      'herald_buffer_flush',
    );
  }

  $buffer = new AlertBuffer();
  $sent = 0;
  $failed = 0;
  try {
    foreach ($buffer->claimDue() as $group) {
      try {
        $body = AlertBuffer::compose($group['bodies'], $group['window'], $group['amounts']);
        if ($body !== '') {
          Dispatcher::deliver(
            $body,
            AlertBuffer::composeDelivery($group['delivery'], $group['bodies'], $group['window'], $group['amounts']),
          );
        }
        $buffer->deleteRows($group['ids']);
        $sent++;
      }
      catch (\Throwable $e) {
        $failed++;
        // Unclaim first: the rows must be visible again to the next run.
        $buffer->release($group['ids']);
        \Civi::log()->warning('herald: buffer flush failed for group, will retry next run', [
          'group_hash' => $group['group_hash'],
          'count' => count($group['ids']),
          'error' => $e->getMessage(),
        ]);
      }
    }
  }
  finally {
    $lock->release();
  }

  return civicrm_api3_create_success(
    [['sent' => $sent, 'failed' => $failed]],
    $params,
    'Job',
    'herald_buffer_flush',
  );
}
