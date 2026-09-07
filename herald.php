<?php

declare(strict_types = 1);

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
  // Enable runs at deploy time, where a broken handler class failing loudly is wanted.
  \Civi\Herald\RuleHealth::refresh();
}

/**
 * Implements hook_civicrm_check().
 *
 * Reads only the cached RuleHealth scan: loading a handler class here could
 * take down the very System.check that reports it.
 *
 * @param array<int, CRM_Utils_Check_Message> $messages
 */
function herald_civicrm_check(&$messages): void {
  $broken = \Civi\Herald\RuleHealth::stored();
  if ($broken === []) {
    return;
  }
  $items = '';
  foreach ($broken as $class => $reason) {
    $items .=
      '<li><code>' . htmlspecialchars((string) $class) . '</code> — ' . htmlspecialchars((string) $reason) . '</li>';
  }
  $messages[] = new CRM_Utils_Check_Message(
    'herald_rule_handler_unloadable',
    E::ts(
      '%1 Herald rule handler class(es) cannot be loaded. Any CiviRules rule using them will fail — under CiviRules\' synchronous evaluation that can 500 the triggering write (e.g. event edits, booking cart). Fix the class or disable the rule.',
      [1 => count($broken)],
    )
    . '<ul>'
    . $items
    . '</ul>',
    E::ts('Herald: unloadable rule handler class'),
    // PSR-3 level; Psr\Log\LogLevel is only a transitive dependency here.
    'critical',
    'fa-bolt',
  );
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
    $contributionId = (int) $id;
    $previousStatusId = \CRM_Core_DAO::singleValueQuery(
      'SELECT contribution_status_id FROM civicrm_contribution WHERE id = %1',
      [1 => [$contributionId, 'Integer']],
    );
    \Civi\Herald\StatusTransitionDispatcher::rememberPreEditStatus(
      $contributionId,
      is_scalar($previousStatusId) ? (int) $previousStatusId : NULL,
    );
    return;
  }
  if ($objectName === 'Event') {
    // Lets the "Event deactivated" condition tell a 1 → 0 transition from an edit while inactive.
    $eventId = (int) $id;
    $previousActive = \CRM_Core_DAO::singleValueQuery(
      'SELECT is_active FROM civicrm_event WHERE id = %1',
      [1 => [$eventId, 'Integer']],
    );
    \Civi\Herald\PreEditSnapshot::remember(
      'Event',
      $eventId,
      'is_active',
      is_scalar($previousActive) ? (int) $previousActive : NULL,
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

/**
 * Implements hook_civicrm_heraldChecks().
 *
 * Parked rows stay in the table by design, so they get their own check
 * instead of counting as backlog forever.
 *
 * @param array<string, callable> $checks
 */
function herald_civicrm_heraldChecks(array &$checks): void {
  $checks['herald.buffer_backlog'] = function (): array {
    $stuck = (int) CRM_Core_DAO::singleValueQuery('
      SELECT COUNT(*) FROM `'
    . \Civi\Herald\Buffer\AlertBuffer::TABLE
    . '`
      WHERE parked_at IS NULL
        AND created_date < DATE_SUB(NOW(), INTERVAL window_minutes * 3 MINUTE)
    ');
    return (
      $stuck > 0
        ? [
          'status' => 'fail',
          'detail' => "{$stuck} buffered alert(s) older than 3x their window — flush job not delivering",
        ]
        : ['status' => 'ok']
    );
  };

  $checks['herald.buffer_dead_letter'] = function (): array {
    $parked = (int) CRM_Core_DAO::singleValueQuery('
      SELECT COUNT(*) FROM `'
    . \Civi\Herald\Buffer\AlertBuffer::TABLE
    . '`
      WHERE parked_at IS NOT NULL
    ');
    return (
      $parked > 0
        ? [
          'status' => 'fail',
          'detail' => "{$parked} alert(s) parked in the dead letter — inspect with Herald.getParkedAlerts",
        ]
        : ['status' => 'ok']
    );
  };
}
