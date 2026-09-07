<?php

declare(strict_types = 1);

namespace Civi\Herald;

/**
 * Request-scoped snapshot of the pre-edit Contribution status, taken in
 * hook_civicrm_pre because hook_civicrm_post only sees the written row.
 */
class StatusTransitionDispatcher {

  /**
   * @var array<int, int> Pre-edit status snapshot keyed by contribution ID */
  private static array $preEditStatus = [];

  /**
   * First-wins pro Contribution und Request: Payment.create/completeOrder editieren
   * mehrfach; ein Überschreiben ließe den Vorher-Status auf "Completed" enden.
   */
  public static function rememberPreEditStatus(int $contributionId, ?int $statusId): void {
    if ($statusId !== NULL && !isset(self::$preEditStatus[$contributionId])) {
      self::$preEditStatus[$contributionId] = $statusId;
    }
  }

  /**
   * Intentionally a no-op: CiviRules triggers read the snapshot via getPreEditStatus().
   */
  public static function handlePostHook(int $contributionId, mixed &$objectRef): void {
  }

  public static function getPreEditStatus(int $contributionId): ?int {
    return self::$preEditStatus[$contributionId] ?? NULL;
  }

  /**
   * CiviRules original data first (per edit, survives delayed queue runs),
   * the request-wide snapshot as fallback.
   *
   * @param int|null $originalStatusId contribution_status_id from the caller's
   *   own pre-edit record; the CiviRules adapter unpacks it from the trigger.
   */
  public static function resolvePreEditStatus(int $contributionId, ?int $originalStatusId = NULL): ?int {
    // Original-Daten sind pro Edit erfasst: nur der Edit, der wirklich schaltet,
    // hat original=Pending — der first-wins-Snapshot kann das nicht unterscheiden.
    return $originalStatusId ?? self::getPreEditStatus($contributionId);
  }

}
