<?php

require_once 'herald.civix.php';

use CRM_Herald_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
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
 */
function herald_civicrm_pre($op, $objectName, $id, &$params): void {
  if ($objectName !== 'Contribution' || $op !== 'edit' || !$id) {
    return;
  }
  $previousStatusId = \CRM_Core_DAO::singleValueQuery(
    'SELECT contribution_status_id FROM civicrm_contribution WHERE id = %1',
    [1 => [(int) $id, 'Integer']]
  );
  \Civi\Herald\StatusTransitionDispatcher::rememberPreEditStatus((int) $id, $previousStatusId !== NULL ? (int) $previousStatusId : NULL);
}

/**
 * Implements hook_civicrm_post().
 *
 * Detects Contribution status transitions and dispatches an internal event so
 * CiviRules-style triggers / channels can react.
 */
function herald_civicrm_post($op, $objectName, $objectId, &$objectRef): void {
  if ($objectName !== 'Contribution' || $op !== 'edit') {
    return;
  }
  \Civi\Herald\StatusTransitionDispatcher::handlePostHook((int) $objectId, $objectRef);
}
