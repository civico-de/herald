<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Job.herald_buffer_flush end to end, against the email channel with
 * CiviCRM's outbound mail redirected into civicrm_mailing_spool — one composed
 * message per group, and a subject that describes what was composed.
 *
 * @group headless
 */
class BufferFlushTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const GROUP = 'herald-test-flush';

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    // No DDL: the install step created the buffer table, and a CREATE TABLE
    // would implicitly commit and defeat the transactional rollback.
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_herald_buffer');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
    \Civi::settings()->set('mailing_backend', [
      'outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB,
    ]);
    \Civi::settings()->set('herald_email_from', '"Herald" <noreply@example.org>');
  }

  private function buffer(string $subject, string $body, float $amount): void {
    (new AlertBuffer())->add(
      self::GROUP,
      5,
      $body,
      [
        'channel' => 'email',
        'email_to' => 'ops@example.org',
        'email_format' => 'text',
        // What the alert's own event rendered — one per buffered row.
        'email_subject' => $subject,
      ],
      $amount,
    );
  }

  /**
   * Subject headers are RFC 2047 encoded once they leave ASCII; decode before
   * asserting on them.
   */
  private function spooledSubject(): string {
    $headers = (string) \CRM_Core_DAO::singleValueQuery(
      'SELECT headers FROM civicrm_mailing_spool ORDER BY id LIMIT 1',
    );
    return mb_decode_mimeheader($headers);
  }

  public function testAggregateGoesOutOnceWithASubjectDescribingTheAggregate(): void {
    $this->buffer('Donation #1', 'first alert', 60.0);
    $this->buffer('Donation #2', 'second alert', 35.0);
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_herald_buffer SET created_date = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE group_hash = %1',
      [1 => [self::GROUP, 'String']],
    );

    $result = civicrm_api3('Job', 'herald_buffer_flush', []);
    $counts = (array) reset($result['values']);

    $this->assertSame(1, (int) $counts['sent']);
    $this->assertSame(0, (int) $counts['failed']);
    $this->assertSame(
      1,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_spool'),
      'a flushed group is ONE message',
    );

    $subject = $this->spooledSubject();
    $this->assertStringContainsString('2 messages', $subject);
    $this->assertStringNotContainsString('Donation #', $subject, 'the aggregate must not be titled after one alert');

    $body = (string) \CRM_Core_DAO::singleValueQuery('SELECT body FROM civicrm_mailing_spool ORDER BY id LIMIT 1');
    $this->assertStringContainsString('first alert', $body);
    $this->assertStringContainsString('second alert', $body);

    $this->assertSame(
      0,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer'),
      'rows are deleted once the composed message went out',
    );
  }

  /**
   * The job has to read herald_buffer_max_items and hand it to compose() —
   * without that wiring the cap exists but never applies, which is how a bulk
   * operation put 893 positions into a single message in production.
   */
  public function testItemCapFromSettingsIsAppliedByTheJob(): void {
    \Civi::settings()->set('herald_buffer_max_items', 3);
    for ($i = 1; $i <= 10; $i++) {
      $this->buffer("Donation #{$i}", "alert {$i}", 10.0);
    }
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_herald_buffer SET created_date = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE group_hash = %1',
      [1 => [self::GROUP, 'String']],
    );

    $result = civicrm_api3('Job', 'herald_buffer_flush', []);
    $this->assertSame(1, (int) ((array) reset($result['values']))['sent']);

    $body = (string) \CRM_Core_DAO::singleValueQuery('SELECT body FROM civicrm_mailing_spool ORDER BY id LIMIT 1');
    $this->assertStringContainsString('10 messages', $this->spooledSubject(), 'the header keeps the true total');
    $this->assertStringContainsString('alert 3', $body);
    $this->assertStringNotContainsString('alert 4', $body);
    $this->assertStringContainsString('and 7 more', $body);

    $this->assertSame(
      0,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer'),
      'capping the printed positions still clears every buffered row',
    );
  }

}
