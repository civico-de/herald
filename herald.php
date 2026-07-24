<?php

require_once 'herald.civix.php';

use CRM_Herald_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @param \CRM_Core_Config|array<string, mixed> $config
 */
function herald_civicrm_config(&$config): void {
  _herald_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 */
function herald_civicrm_install(): void {
  _herald_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 */
function herald_civicrm_enable(): void {
  _herald_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_pre().
 *
 * Captures the pre-edit Contribution status so post-hook callers can detect
 * transitions (e.g. Pending → Completed). hook_civicrm_post fires AFTER the
 * row was written so the old value is otherwise unreachable.
 *
 * Signature is dictated by CiviCRM: $id is NULL on create and an int-ish
 * string or int on edit/delete; $params is the (mutable) field bag.
 *
 * @param string $op
 * @param string $objectName
 * @param int|string|null $id
 * @param array<string, mixed> $params
 */
function herald_civicrm_pre($op, $objectName, $id, &$params): void {
  if ($op !== 'edit' || !$id) {
    return;
  }
  if ($objectName === 'Contribution') {
    $contributionId = (int) $id;
    $previousStatusId = \CRM_Core_DAO::singleValueQuery(
      'SELECT contribution_status_id FROM civicrm_contribution WHERE id = %1',
      [1 => [$contributionId, 'Integer']]
    );
    \Civi\Herald\StatusTransitionDispatcher::rememberPreEditStatus(
      $contributionId,
      is_scalar($previousStatusId) ? (int) $previousStatusId : NULL
    );
    return;
  }
  if ($objectName === 'Event') {
    // Pre-edit is_active, so the "Event deactivated" condition can tell a
    // genuine 1 → 0 transition from an unrelated edit while already inactive.
    $eventId = (int) $id;
    $previousActive = \CRM_Core_DAO::singleValueQuery(
      'SELECT is_active FROM civicrm_event WHERE id = %1',
      [1 => [$eventId, 'Integer']]
    );
    \Civi\Herald\PreEditSnapshot::remember(
      'Event',
      $eventId,
      'is_active',
      is_scalar($previousActive) ? (int) $previousActive : NULL
    );
  }
}

/**
 * Implements hook_civicrm_post().
 *
 * Detects Contribution status transitions and dispatches an internal event so
 * CiviRules-style triggers / channels can react.
 *
 * Signature is dictated by CiviCRM: $objectRef is the DAO (or, for some
 * entities, an array) that was just written, passed by reference and free to be
 * replaced by any listener — hence `mixed` rather than a narrower union.
 *
 * @param string $op
 * @param string $objectName
 * @param int|string|null $objectId
 * @param mixed $objectRef
 */
function herald_civicrm_post($op, $objectName, $objectId, &$objectRef): void {
  if ($objectName !== 'Contribution' || $op !== 'edit') {
    return;
  }
  \Civi\Herald\StatusTransitionDispatcher::handlePostHook((int) $objectId, $objectRef);
}

/**
 * hook_civicrm_heraldChecks — herald watches its own delivery pipeline.
 *
 * A buffered alert older than 3× its aggregation window means the flush job
 * is not sending (job disabled, channel down, cron stopped mid-way). The
 * monitor cannot see its own broken transport otherwise.
 *
 * @param array<string, callable> $checks
 */
function herald_civicrm_heraldChecks(array &$checks): void {
  $checks['herald.buffer_backlog'] = function (): array {
    $stuck = (int) CRM_Core_DAO::singleValueQuery('
      SELECT COUNT(*) FROM `' . \Civi\Herald\Buffer\AlertBuffer::TABLE . '`
      WHERE created_date < DATE_SUB(NOW(), INTERVAL window_minutes * 3 MINUTE)
    ');
    return $stuck > 0
      ? ['status' => 'fail', 'detail' => "$stuck buffered alert(s) older than 3x their window — flush job not delivering"]
      : ['status' => 'ok'];
  };
}
