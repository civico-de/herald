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
   *
   * FIRST-WINS pro Contribution und Request: Payment.create/completeOrder
   * editieren die Contribution MEHRFACH (Statuswechsel, dann Custom-Felder/
   * Quittungsdatum). CiviRules wertet die Regeln erst nach Commit aus — würde
   * jeder Edit den Snapshot überschreiben, stünde dort am Ende der NEUE
   * Status (Completed) statt des echten Vorher-Status (Pending), und die
   * Pending→Completed-Erkennung wäre für alle Zahlungs-Pfade blind
   * (gefunden 23.07.2026 auf production: PayPal-Verkäufe ohne jede Meldung).
   */
  public static function rememberPreEditStatus(int $contributionId, ?int $statusId): void {
    if ($statusId !== NULL && !isset(self::$preEditStatus[$contributionId])) {
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
    // Original-Daten ZUERST: CiviRules erfasst sie PRO EDIT. Payment.create/
    // completeOrder editieren mehrfach in einer Transaktion; nur der Edit, der
    // wirklich Pending→Completed schaltet, hat original=Pending — die
    // Folge-Edits original=Completed und matchen nicht (kein Doppel-Alert).
    // Der Request-weite Snapshot (first-wins) kann das nicht unterscheiden
    // und bleibt Fallback für Pfade ohne OriginalData.
    if ($triggerData instanceof \CRM_Civirules_TriggerData_Interface_OriginalData
      && strtolower((string) $triggerData->getOriginalEntity()) === 'contribution') {
      $original = $triggerData->getOriginalData();
      if (!empty($original['contribution_status_id']) && is_scalar($original['contribution_status_id'])) {
        return (int) $original['contribution_status_id'];
      }
    }
    return self::getPreEditStatus($contributionId);
  }

}
