<?php

declare(strict_types = 1);

use Civi\Api4\Activity;
use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Contact;
use Civi\Herald\Schedule\Clock;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * A rule "Herald: Schedule" + "Herald: Send digest" run through Civirules.cron.
 *
 * CiviRules stamps log_date with PHP's real clock, which CRM_Utils_Time does
 * not move, so each cron run restamps its log rows with the simulated time.
 * 2030-01-07 is a Monday. Not
 * transactional: inside a transaction CiviRules' cron lock is released only on
 * commit, so a second cron run would find its rule locked.
 *
 * @group headless
 */
class CRM_Herald_CivirulesAdapter_Trigger_ScheduleTriggerTest extends TestCase implements HeadlessInterface {

  /**
   * @var list<int>
   */
  private array $rules = [];

  /**
   * @var list<int>
   */
  private array $contacts = [];

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function tearDown(): void {
    CRM_Utils_Time::resetTime();
    foreach ($this->contacts as $contact) {
      Activity::delete(checkPermissions: FALSE)->addWhere('assignee_contact_id', 'CONTAINS', $contact)->execute();
      Contact::delete(checkPermissions: FALSE)->setUseTrash(FALSE)->addWhere('id', '=', $contact)->execute();
    }
    foreach ($this->rules as $rule) {
      foreach (['civirule_rule_log', 'civirule_rule_action'] as $table) {
        CRM_Core_DAO::executeQuery("DELETE FROM {$table} WHERE rule_id = %1", [1 => [$rule, 'Integer']]);
      }
      CRM_Core_DAO::executeQuery('DELETE FROM civirule_rule WHERE id = %1', [1 => [$rule, 'Integer']]);
    }
    parent::tearDown();
  }

  public function testWeeklyDigestGoesOutOncePerSlot(): void {
    $this->moveClock('2030-01-07 07:00');
    $assignee = (int) Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Digest')
      ->addValue('last_name', 'Reader')
      ->execute()
      ->single()['id'];
    $this->contacts[] = $assignee;
    $rule = $this->scheduledDigestRule($assignee, ['weekdays' => [1], 'hour' => 8]);

    $this->cronAt('2030-01-07 07:30');
    self::assertSame(0, $this->digests($assignee), 'before the slot');

    $this->cronAt('2030-01-07 08:05');
    self::assertSame(1, $this->digests($assignee), 'at the slot');

    $this->cronAt('2030-01-07 08:20');
    self::assertSame(1, $this->digests($assignee), 'the next cron run sends no second digest');

    $this->cronAt('2030-01-08 08:05');
    self::assertSame(1, $this->digests($assignee), 'Tuesday is not a slot');

    // Cron missed the 8 o'clock hour; the next run catches up.
    $this->cronAt('2030-01-14 09:40');
    self::assertSame(2, $this->digests($assignee), 'catch-up after a missed hour');

    self::assertSame(2, (int) CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM civirule_rule_log WHERE rule_id = %1 AND entity_table = 'civirule_rule' AND entity_id = %1 AND contact_id IS NULL",
      [1 => [$rule, 'Integer']],
    ));
  }

  public function testInvalidScheduleIsLoggedAndOtherRulesStillRun(): void {
    $this->moveClock('2030-01-07 07:00');
    $broken = $this->scheduledRule(['weekdays' => [1]]);
    $working = $this->scheduledRule(['hour' => 8]);

    $result = $this->cronAt('2030-01-07 08:05');

    self::assertSame(0, $result[$broken]['triggered_entities'] ?? NULL);
    self::assertSame(1, $result[$working]['triggered_entities'] ?? NULL);
  }

  /**
   * @param array<string, mixed> $schedule
   */
  private function scheduledDigestRule(int $assignee, array $schedule): int {
    $rule = $this->scheduledRule($schedule);
    CiviRulesRuleAction::create(checkPermissions: FALSE)
      ->addValue('rule_id', $rule)
      ->addValue('action_id', $this->id(CiviRulesAction::get(checkPermissions: FALSE), 'herald_digest'))
      ->addValue('action_params', [
        'channel' => 'activity',
        'activity_assignee_contact_id' => $assignee,
        'template' => 'herald_contribution_digest_weekly',
        'period' => 'last_7_days',
      ])
      ->addValue('is_active', TRUE)
      ->execute();
    return $rule;
  }

  /**
   * @param array<string, mixed> $schedule
   */
  private function scheduledRule(array $schedule): int {
    $rule = (int) CiviRulesRule::create(checkPermissions: FALSE)
      ->addValue('name', 'herald_test_schedule_' . uniqid())
      ->addValue('label', 'Weekly digest')
      ->addValue('trigger_id', $this->id(CiviRulesTrigger::get(checkPermissions: FALSE), 'herald_schedule'))
      ->addValue('trigger_params', serialize($schedule + ['since' => CRM_Utils_Time::time()]))
      ->addValue('is_active', TRUE)
      ->execute()
      ->single()['id'];
    $this->rules[] = $rule;
    return $rule;
  }

  private function id(\Civi\Api4\Generic\AbstractGetAction $get, string $name): int {
    return (int) $get->addSelect('id')->addWhere('name', '=', $name)->execute()->single()['id'];
  }

  /**
   * @return array<int|string, array<string, mixed>>
   */
  private function cronAt(string $local): array {
    $this->moveClock($local);
    $before = (int) CRM_Core_DAO::singleValueQuery('SELECT COALESCE(MAX(id), 0) FROM civirule_rule_log');
    $result = civicrm_api3('Civirules', 'cron', []);
    CRM_Core_DAO::executeQuery('UPDATE civirule_rule_log SET log_date = %1 WHERE id > %2', [
      1 => [date('Y-m-d H:i:s', CRM_Utils_Time::time()), 'String'],
      2 => [$before, 'Integer'],
    ]);
    return is_array($result['values'] ?? NULL) ? $result['values'] : [];
  }

  private function moveClock(string $local): void {
    CRM_Utils_Time::setTime((new DateTimeImmutable($local, Clock::zone()))->getTimestamp());
  }

  private function digests(int $assignee): int {
    return Activity::get(checkPermissions: FALSE)
      ->addWhere('activity_type_id:name', '=', 'herald_notification')
      ->addWhere('assignee_contact_id', 'CONTAINS', $assignee)
      ->execute()
      ->count();
  }

}
