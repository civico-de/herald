<?php

declare(strict_types = 1);

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Buffer\DeadLetter;
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
 * Dead letter: a group that resolves to no recipients can never succeed, so it
 * is parked at once; other failures are parked after herald_buffer_max_attempts
 * tries. Parked rows stay in the table (Herald.getParkedAlerts) and only a
 * deliberate Herald.reactivateParkedAlerts puts them back in circulation.
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
  $deadLetter = new DeadLetter($buffer);
  $sent = 0;
  $failed = 0;
  $parked = 0;
  $maxAttempts = (int) \Civi::settings()->get('herald_buffer_max_attempts');
  $maxItems = (int) \Civi::settings()->get('herald_buffer_max_items');
  try {
    foreach ($buffer->claimDue() as $group) {
      try {
        $body = AlertBuffer::compose($group['bodies'], $group['window'], $group['amounts'], $maxItems);
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
        // Park-or-retry policy lives in DeadLetter: an unresolvable fan-out
        // never becomes deliverable by trying again.
        $wasParked = $deadLetter->handleFailure($group['ids'], $e, $maxAttempts);
        $parked += $wasParked ? 1 : 0;
        \Civi::log()->warning(
          $wasParked
            ? 'herald: buffer flush parked a group in the dead letter'
            : 'herald: buffer flush failed for group, will retry next run',
          [
            'group_hash' => $group['group_hash'],
            'count' => count($group['ids']),
            'error' => $e->getMessage(),
          ],
        );
      }
    }
  }
  finally {
    $lock->release();
  }

  return civicrm_api3_create_success(
    [['sent' => $sent, 'failed' => $failed, 'parked' => $parked]],
    $params,
    'Job',
    'herald_buffer_flush',
  );
}
