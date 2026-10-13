<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Schedule\Clock;
use Civi\Herald\Schedule\Schedule;
use Civi\Herald\Schedule\ScheduleParams;

/**
 * Is the latest slot of a rule's schedule due? Backs the "Herald: Schedule"
 * CiviRules trigger: due once per slot, judged by the rule's own run log.
 *
 * @method $this setRuleId(int $ruleId)
 * @method $this setSchedule(array<array-key, mixed> $schedule)
 */
class CheckSchedule extends AbstractAction {

  /**
   * The CiviRules rule whose runs count.
   * @var int
   * @required
   */
  protected $ruleId;

  /**
   * The rule's trigger params: weekdays (ISO 1–7, empty = every day), hour (0–23), since.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $schedule = [];

  public function _run(Result $result): void {
    $schedule = ScheduleParams::parse($this->schedule);
    $slot = $schedule->dueSlot(Clock::now());
    $lastRun = $this->lastRun();
    $notBefore = max($this->created(), $schedule->since);
    $result[] = [
      'due' => Schedule::fires($slot->getTimestamp(), $lastRun, $notBefore),
      'slot' => $slot->format(\DATE_ATOM),
      'last_run' => self::format($lastRun),
      'not_before' => self::format($notBefore),
    ];
  }

  /**
   * CiviRules writes log_date through APIv4, whose "now" default is PHP's wall
   * time; it is read back in PHP's timezone, not the session's fixed offset.
   */
  private function lastRun(): ?int {
    $logged = civicrm_api4('CiviRulesRuleLog', 'get', [
      'checkPermissions' => FALSE,
      'select' => ['log_date'],
      'where' => [['rule_id', '=', $this->ruleId]],
      'orderBy' => ['log_date' => 'DESC'],
      'limit' => 1,
    ])->first()['log_date'] ?? NULL;
    return is_string($logged) ? self::wallTime($logged) : NULL;
  }

  private function created(): int {
    $created = civicrm_api4('CiviRulesRule', 'get', [
      'checkPermissions' => FALSE,
      'select' => ['created_date'],
      'where' => [['id', '=', $this->ruleId]],
    ])->first()['created_date'] ?? NULL;
    if (!is_string($created)) {
      throw new \CRM_Core_Exception("herald: CiviRules rule {$this->ruleId} does not exist");
    }
    return self::wallTime($created);
  }

  private static function wallTime(string $value): int {
    return (new \DateTimeImmutable($value, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
  }

  private static function format(?int $time): ?string {
    return $time === NULL
      ? NULL
      : (new \DateTimeImmutable('@' . $time))
        ->setTimezone(Clock::zone())
        ->format(\DATE_ATOM);
  }

}
