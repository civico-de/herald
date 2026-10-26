<?php

declare(strict_types = 1);

require_once 'herald.civix.php';
// Bundled packages, prefixed into Civi\Herald\Vendor\ by `composer install`.
require_once __DIR__ . '/vendor/autoload.php';

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
 * Snapshots pre-edit values that hook_civicrm_post can no longer see.
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
  // Watch list is empty unless a field-transition condition is configured.
  \Civi\Herald\Condition\FieldTransitionSnapshotter::capture($objectName, (int) $id);
  if ($objectName === 'Contribution') {
    // from_status in contribution alerts when the trigger carries no original data.
    $contributionId = (int) $id;
    // By id, so test contributions are found too.
    $previousStatusId =
      \Civi\Api4\Contribution::get(checkPermissions: FALSE)
        ->addSelect('contribution_status_id')
        ->addWhere('id', '=', $contributionId)
        ->execute()
        ->first()['contribution_status_id'] ?? NULL;
    \Civi\Herald\StatusTransitionDispatcher::rememberPreEditStatus(
      $contributionId,
      is_scalar($previousStatusId) ? (int) $previousStatusId : NULL,
    );
  }
}

/**
 * Implements hook_civicrm_post().
 *
 * $objectRef may be a DAO or an array and any listener may replace it — hence `mixed`.
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
 * Implements hook_civicrm_validateForm().
 *
 * @param string $formName
 * @param array<array-key, mixed> $fields
 * @param array<array-key, mixed> $files
 * @param \CRM_Core_Form $form
 * @param array<array-key, mixed> $errors
 */
function herald_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors): void {
  \Civi\Herald\Subscribers\SubscriptionForm::validate((string) $formName, $fields, $errors);
}

/**
 * Implements hook_civicrm_alterLogTables().
 *
 * The alert buffer is a transient work queue; logging it costs an INSERT and
 * a DELETE per row during bulk operations for a history nobody reads.
 *
 * @param array<string, mixed> $logTableSpec
 */
function herald_civicrm_alterLogTables(array &$logTableSpec): void {
  unset($logTableSpec[\Civi\Herald\Buffer\AlertBuffer::TABLE]);
}
