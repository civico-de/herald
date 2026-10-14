<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Dead-letter path of Job.herald_buffer_flush.
 *
 * The failure that motivates it: a buffered group whose fan-out resolves to
 * zero recipients throws on every flush, so without parking the same rows are
 * claimed, fail and released on every single cron run, forever.
 *
 * @group headless
 */
class BufferDeadLetterTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const GROUP = 'herald-test-deadletter';

  private AlertBuffer $buffer;

  private DeadLetter $deadLetter;

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    // No DDL: the install step created the table, and a CREATE TABLE would
    // implicitly commit and defeat the transactional rollback.
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_herald_buffer');
    $this->buffer = new AlertBuffer();
    $this->deadLetter = new DeadLetter($this->buffer);
  }

  /**
   * Buffer one alert whose delivery params are due immediately.
   *
   * @param array<string, mixed> $delivery
   */
  private function bufferDue(array $delivery, string $body = 'alert body'): void {
    $this->buffer->add(self::GROUP, 5, $body, $delivery);
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_herald_buffer SET created_date = DATE_SUB(NOW(), INTERVAL 60 MINUTE) WHERE group_hash = %1',
      [1 => [self::GROUP, 'String']],
    );
  }

  /**
   * @return array{sent: int, failed: int, parked: int}
   */
  private function flush(): array {
    $result = civicrm_api3('Job', 'herald_buffer_flush', []);
    $counts = (array) reset($result['values']);
    return [
      'sent' => (int) ($counts['sent'] ?? 0),
      'failed' => (int) ($counts['failed'] ?? 0),
      'parked' => (int) ($counts['parked'] ?? 0),
    ];
  }

  private function parkedCount(): int {
    return (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM civicrm_herald_buffer WHERE parked_at IS NOT NULL',
    );
  }

  /**
   * Group mode against a group with no contacts: the flush must park on the
   * FIRST failure — retrying re-resolves the same empty list.
   */
  public function testEmptyRecipientListIsParkedImmediately(): void {
    $groupId = (int) \Civi\Api4\Group::create(checkPermissions: FALSE)
      ->addValue('name', 'herald_test_empty_group')
      ->addValue('title', 'herald test empty group')
      ->execute()
      ->first()['id'];

    $this->bufferDue(['delivery_mode' => 'group', 'group_id' => $groupId, 'channel' => 'email']);

    $first = $this->flush();
    $this->assertSame(1, $first['failed']);
    $this->assertSame(1, $first['parked'], 'an unresolvable fan-out is parked on the first attempt');
    $this->assertSame(1, $this->parkedCount());

    $row = $this->deadLetter->getParked()[0];
    $this->assertSame('no_recipients', $row['park_reason']);
    $this->assertStringContainsString('no recipients resolved', $row['last_error']);

    // The regression proper: a parked row is never claimed again, so the next
    // run has nothing to do instead of failing forever.
    $second = $this->flush();
    $this->assertSame(0, $second['failed']);
    $this->assertSame(0, $second['sent']);
    $this->assertSame(1, $this->parkedCount(), 'nothing is deleted — the alert stays visible');
  }

  /**
   * A transient failure (transport down) keeps retrying until the configured
   * budget is exhausted, then parks.
   */
  public function testTransientFailureIsParkedAfterMaxAttempts(): void {
    \Civi::settings()->set('herald_buffer_max_attempts', 3);
    // No such channel key: Delivery::send() throws a plain exception, i.e. the
    // "retryable" error class.
    $this->bufferDue(['channel' => 'no-such-channel']);

    $this->assertSame(['sent' => 0, 'failed' => 1, 'parked' => 0], $this->flush());
    $this->assertSame(0, $this->parkedCount(), 'attempt 1 stays in the queue');

    $this->assertSame(['sent' => 0, 'failed' => 1, 'parked' => 0], $this->flush());
    $this->assertSame(0, $this->parkedCount(), 'attempt 2 stays in the queue');

    $this->assertSame(['sent' => 0, 'failed' => 1, 'parked' => 1], $this->flush());
    $this->assertSame(1, $this->parkedCount(), 'attempt 3 exhausts the budget');

    $row = $this->deadLetter->getParked()[0];
    $this->assertSame('max_attempts', $row['park_reason']);
    $this->assertSame(3, $row['attempts']);

    $this->assertSame(['sent' => 0, 'failed' => 0, 'parked' => 0], $this->flush());
  }

  /**
   * Reactivation is deliberate, resets the budget and makes the item flushable
   * again — here it then succeeds through the spool.
   */
  public function testReactivationPutsAParkedAlertBackIntoCirculation(): void {
    \Civi::settings()->set('herald_buffer_max_attempts', 1);
    $this->bufferDue(['channel' => 'no-such-channel']);
    $this->flush();
    $this->assertSame(1, $this->parkedCount());

    $parkedId = $this->deadLetter->getParked()[0]['id'];
    $this->assertSame(
      1,
      \Civi\Api4\Herald::reactivateParkedAlerts(checkPermissions: FALSE)
        ->setIds([$parkedId])
        ->execute()
        ->first()['reactivated'],
    );

    $this->assertSame(0, $this->parkedCount());
    $this->assertSame([], $this->deadLetter->getParked());
    $this->assertSame(
      0,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(attempts) FROM civicrm_herald_buffer'),
      'the retry budget is reset, not continued',
    );

    // Repair the config the way an admin would, then let it flush for real.
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_herald_buffer SET delivery = %1, created_date = DATE_SUB(NOW(), INTERVAL 60 MINUTE)',
      [1 => [json_encode(['channel' => 'email', 'email_to' => 'ops@example.org', 'email_format' => 'text']), 'String']],
    );
    \Civi::settings()->set('mailing_backend', [
      'outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB,
    ]);
    \Civi::settings()->set('herald_email_from', '"Herald" <noreply@example.org>');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');

    $this->assertSame(1, $this->flush()['sent']);
    $this->assertSame(
      0,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer'),
      'a reactivated alert follows the normal path: delivered, then deleted',
    );
  }

  /**
   * getParkedAlerts is the browsable view of the dead letter.
   */
  public function testGetParkedAlertsExposesTheDeadLetter(): void {
    \Civi::settings()->set('herald_buffer_max_attempts', 1);
    $this->bufferDue(['channel' => 'no-such-channel']);
    $this->flush();

    $rows = (array) \Civi\Api4\Herald::getParkedAlerts(checkPermissions: FALSE)->execute()->getArrayCopy();
    $this->assertCount(1, $rows);
    $this->assertSame(self::GROUP, $rows[0]['group_hash']);
    $this->assertArrayNotHasKey('body', $rows[0], 'the dead-letter view carries no rendered content');
  }

}
