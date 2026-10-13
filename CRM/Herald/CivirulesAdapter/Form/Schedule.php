<?php

declare(strict_types = 1);

use Civi\Herald\Schedule\ScheduleParams;
use Civi\Herald\Schedule\ScheduleText;
use CRM_Herald_ExtensionUtil as E;

/**
 * Parameter form for the "Herald: Schedule" trigger: weekdays and hour.
 * Saving restarts the schedule, so no slot from before the save fires.
 * CiviRules opens its parameter screens as QuickForm forms by route.
 * @mago-expect analysis:civikitchen/legacy-ui-base
 */
class CRM_Herald_CivirulesAdapter_Form_Schedule extends CRM_CivirulesTrigger_Form_Form {

  public function buildQuickForm(): void {
    $this->add('hidden', 'rule_id');
    $this->add('select', 'weekdays', E::ts('Weekdays'), ScheduleText::weekdayNames(), FALSE, [
      'multiple' => 'multiple',
      'class' => 'crm-select2 huge',
      'placeholder' => E::ts('- every day -'),
    ]);
    $hours = [];
    foreach (range(0, 23) as $hour) {
      $hours[$hour] = sprintf('%02d:00', $hour);
    }
    $this->add('select', 'hour', E::ts('Time'), $hours, TRUE);
    $this->addButtons([
      ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function setDefaultValues() {
    /** @var array<string, mixed> $defaults */
    $defaults = parent::setDefaultValues();
    $params = $this->triggerClass->getTriggerParams();
    $defaults['weekdays'] = $params['weekdays'] ?? [];
    $defaults['hour'] = $params['hour'] ?? 8;
    return $defaults;
  }

  /**
   * A cron trigger has no op; the base form would pass its NULL to str_contains().
   */
  protected function supportsMultipleTriggerOps(): bool {
    return FALSE;
  }

  public function postProcess(): void {
    $weekdays = $this->getSubmittedValue('weekdays');
    $schedule = ScheduleParams::parse([
      'weekdays' => is_array($weekdays) ? array_values($weekdays) : [],
      'hour' => $this->getSubmittedValue('hour'),
      'since' => CRM_Utils_Time::time(),
    ]);
    $this->triggerParams = [
      'weekdays' => $schedule->weekdays,
      'hour' => $schedule->hour,
      'since' => $schedule->since,
    ];
    parent::postProcess();
  }

}
