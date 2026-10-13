<?php

declare(strict_types = 1);

use Civi\Herald\Schedule\ScheduleText;
use CRM_Herald_ExtensionUtil as E;

/**
 * CiviRules adapter: a cron trigger that fires once per slot of the rule's
 * schedule. Whether the slot is due is Herald.checkSchedule; the trigger
 * carries no contact, only the rule itself as its entity.
 *
 * Trigger parameters (via the parameter form): weekdays (ISO 1–7, empty = every
 * day), hour (0–23, CiviCRM's timezone), since (Unix time of the last save).
 */
class CRM_Herald_CivirulesAdapter_Trigger_Schedule extends CRM_Civirules_Trigger_Cron {

  private const ENTITY = 'CiviRulesRule';

  /**
   * process() asks for entities until FALSE; a rule is checked once per cron run.
   */
  private bool $checked = FALSE;

  /**
   * @return CRM_Civirules_TriggerData_Cron|false
   */
  protected function getNextEntityTriggerData() {
    if ($this->checked) {
      return FALSE;
    }
    $this->checked = TRUE;
    $ruleId = $this->getRuleId();
    try {
      // CiviRules runs cron triggers without an acting user.
      $due =
        \Civi\Api4\Herald::checkSchedule(checkPermissions: FALSE)
          ->setRuleId($ruleId)
          ->setSchedule($this->getTriggerParams())
          ->execute()
          ->first()['due'] ?? FALSE;
    }
    catch (CRM_Core_Exception $e) {
      // A throw here would end Civirules.cron for every later rule.
      \Civi::log()->error('herald: rule {rule} has an invalid schedule: {error}', [
        'rule' => $ruleId,
        'error' => $e->getMessage(),
      ]);
      return FALSE;
    }
    if ($due !== TRUE) {
      return FALSE;
    }
    // Contact 0 is no contact: CiviRules tests it with empty(), as its own case cron trigger does.
    return new CRM_Civirules_TriggerData_Cron(0, self::ENTITY, ['id' => $ruleId], $ruleId, $this);
  }

  /**
   * The rule itself, so the rule log names it; no contact is involved.
   */
  protected function reactOnEntity() {
    return new CRM_Civirules_TriggerData_EntityDefinition(
      E::ts('Rule'),
      self::ENTITY,
      'CRM_Civirules_DAO_CiviRulesRule',
      self::ENTITY,
    );
  }

  /**
   * @param int $ruleId
   */
  public function getExtraDataInputUrl($ruleId) {
    return $this->getFormattedExtraDataInputUrl('civicrm/herald/form/civirulestrigger/schedule', (int) $ruleId);
  }

  public function getTriggerDescription(): string {
    return ScheduleText::summary($this->getTriggerParams());
  }

  public function getHelpText(string $context): string {
    return match ($context) {
      'triggerDescription' => E::ts(
        'Fires once at a set hour on the chosen weekdays, without a contact. For actions such as "Herald: Send digest".',
      ),
      'triggerDescriptionWithParams' => $this->getTriggerDescription(),
      default => E::ts(
        'The rule fires once at the full hour on each chosen weekday, in CiviCRM\'s timezone. If cron misses that hour, the next cron run catches up. Only the latest slot is sent, never a backlog, and never one from before the schedule was saved.',
      ),
    };
  }

}
