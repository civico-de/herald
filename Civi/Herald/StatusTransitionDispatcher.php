<?php

namespace Civi\Herald;

/**
 * Captures Pending → Completed (and other) status transitions on Contribution edits
 * so CiviRules-condition code can ask "what was the previous status?".
 *
 * Civi's hook_civicrm_post fires AFTER the row has been written, so we cannot read
 * the old row from the DB. Instead, we read the pre-edit snapshot inside hook_civicrm_pre
 * (registered separately if needed) or rely on $objectRef having ->_oldStatusId set
 * by upstream code. As a fallback for v0.1, the dispatcher exposes the *current* status
 * and leaves transition-detection to the CiviRules condition layer.
 */
class StatusTransitionDispatcher {

  /**
   * @var array<int, int> Pre-edit status snapshot keyed by contribution ID */
  private static array $preEditStatus = [];

  /**
   * Stash the pre-edit status. Call from hook_civicrm_pre.
   */
  public static function rememberPreEditStatus(int $contributionId, ?int $statusId): void {
    if ($statusId !== NULL) {
      self::$preEditStatus[$contributionId] = $statusId;
    }
  }

  /**
   * Called from hook_civicrm_post. For now this is a no-op placeholder; the real
   * dispatch will happen via CiviRules triggers in v0.2. Kept here so CiviRules
   * conditions can inspect the snapshot via getPreEditStatus().
   */
  public static function handlePostHook(int $contributionId, mixed &$objectRef): void {
    // Intentionally minimal in v0.1.
    // Future: dispatch a Symfony event 'herald.contribution.status_changed'
    // that interested CiviRules triggers can subscribe to.
  }

  public static function getPreEditStatus(int $contributionId): ?int {
    return self::$preEditStatus[$contributionId] ?? NULL;
  }

  /**
   * Resolve the pre-edit status: in-request snapshot first, then CiviRules'
   * own original data as fallback.
   *
   * The static snapshot only lives for the current PHP request. When a rule
   * runs delayed (CiviRules queue/cron), the snapshot is gone — but CiviRules
   * serialises the trigger data including original entity values into the
   * queue, so getOriginalData() still works there.
   *
   * @param object|null $triggerData CRM_Civirules_TriggerData_TriggerData
   */
  public static function resolvePreEditStatus(int $contributionId, $triggerData = NULL): ?int {
    $snapshot = self::getPreEditStatus($contributionId);
    if ($snapshot !== NULL) {
      return $snapshot;
    }
    if ($triggerData instanceof \CRM_Civirules_TriggerData_Interface_OriginalData
      && strtolower((string) $triggerData->getOriginalEntity()) === 'contribution') {
      $original = $triggerData->getOriginalData();
      if (!empty($original['contribution_status_id']) && is_scalar($original['contribution_status_id'])) {
        return (int) $original['contribution_status_id'];
      }
    }
    return NULL;
  }

}
