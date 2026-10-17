<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4;

use Civi\Api4\Herald;
use Civi\Api4\Job;
use Civi\Herald\Digest\DigestFixture;
use Civi\Herald\Digest\DigestProblem;
use Civi\Herald\Schedule\Clock;
use Civi\Herald\Schedule\SendWindow;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A digest called on every cron run goes out once a day from its hour on, and
 * a digest whose searches found nothing can stay unsent.
 *
 * @group headless
 */
class NotifyDigestDailyTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const DIGEST = 'herald_test_daily';

  private const DELIVERY = ['channel' => 'email', 'email_to' => 'ops@example.org'];

  /**
   * Display ids by role: 'filled' finds the domain contact, 'empty' nothing,
   * 'total' counts nothing.
   *
   * @var array<string, int>
   */
  private array $displays = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    \Civi::settings()->set('mailing_backend', [
      'outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB,
    ]);
    \Civi::settings()->set('herald_email_from', '"Herald" <noreply@example.org>');
    \Civi::settings()->set('herald_email_default_to', '');
    // The settings bag outlives the test transaction.
    \Civi::settings()->set(SendWindow::SETTING, []);
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
    $searches = [
      'filled' => ['id', ['id', '>', 0]],
      'empty' => ['id', ['id', '<', 0]],
      'total' => ['COUNT(id) AS total', ['id', '<', 0]],
    ];
    foreach ($searches as $role => [$select, $where]) {
      $key = $role === 'total' ? 'total' : 'id';
      $search = \Civi\Api4\SavedSearch::create(checkPermissions: FALSE)
        ->setValues([
          'name' => 'herald_daily_' . $role,
          'label' => 'Herald daily test ' . $role,
          'api_entity' => 'Contact',
          'api_params' => ['version' => 4, 'select' => [$select], 'where' => [$where], 'limit' => 1],
        ])
        ->execute()
        ->single()['id'];
      $this->displays[$role] = (int) \Civi\Api4\SearchDisplay::create(checkPermissions: FALSE)
        ->setValues([
          'saved_search_id' => $search,
          'name' => 'herald_daily_' . $role,
          'label' => $role,
          'type' => 'table',
          'settings' => ['columns' => [['key' => $key, 'label' => $key, 'type' => 'field']]],
        ])
        ->execute()
        ->single()['id'];
    }
  }

  protected function tearDown(): void {
    \CRM_Utils_Time::resetTime();
    parent::tearDown();
  }

  /**
   * @param array<string> $keys
   * @param array<string, mixed> $context
   * @param array<string, mixed> $sections
   */
  public function hook_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
    $sections['test.seats'] = ['rows' => []];
  }

  /**
   * A digest of the given sources: a display role or 'section'.
   *
   * @param list<string> $sources
   * @return array{id: int}
   */
  private function digest(array $sources = ['filled']): array {
    return DigestFixture::create(
      self::DIGEST,
      'Herald daily digest',
      '<p>Digest</p>',
      array_map(
        fn (string $source, int $index): array => (
          ['name' => "source_{$index}"]
          + (
            $source === 'section'
              ? ['type' => 'section', 'section_key' => 'test.seats']
              : ['type' => 'search', 'search_display_id' => $this->displays[$source]]
          )
        ),
        $sources,
        array_keys($sources),
      ),
      'Daily',
    );
  }

  /**
   * @param array<string, mixed> $params sendHour, daily, skipEmpty
   * @param array<string, mixed> $delivery
   * @return array<string, mixed>
   */
  private function notify(array $params, array $delivery = self::DELIVERY): array {
    return Herald::notifyDigest(checkPermissions: FALSE)
      ->setDigest(self::DIGEST)
      ->setActionParams($delivery)
      ->setSendHour($params['sendHour'] ?? NULL)
      ->setDaily($params['daily'] ?? FALSE)
      ->setSkipEmpty($params['skipEmpty'] ?? FALSE)
      ->execute()
      ->single();
  }

  /**
   * Local wall time in CiviCRM's timezone.
   */
  private function setClock(string $local): void {
    \CRM_Utils_Time::setTime((new \DateTimeImmutable($local, Clock::zone()))->getTimestamp());
  }

  /**
   * The recipient of each spooled mail.
   *
   * @return list<string>
   */
  private static function spooledTo(): array {
    $recipients = [];
    $dao = \CRM_Core_DAO::executeQuery('SELECT recipient_email FROM civicrm_mailing_spool ORDER BY id');
    while ($dao->fetch()) {
      $recipients[] = (string) $dao->recipient_email;
    }
    return $recipients;
  }

  public function testBeforeTheHourItSkips(): void {
    $this->digest();
    $this->setClock('2030-01-07 07:59:00');

    $result = $this->notify(['sendHour' => 8, 'daily' => TRUE]);

    self::assertSame(['mode' => 'skipped', 'send_hour' => 8, 'current_hour' => 7], $result);
    self::assertSame([], self::spooledTo());
    self::assertSame([], \Civi::settings()->get(SendWindow::SETTING));
  }

  public function testTheFirstRunAfterTheHourSends(): void {
    $this->digest();
    $this->setClock('2030-01-07 10:15:00');

    self::assertSame(['mode' => 'sent', 'sources' => 1], $this->notify(['sendHour' => 8, 'daily' => TRUE]));
    self::assertSame(['ops@example.org'], self::spooledTo());
  }

  public function testASecondRunTheSameDaySkips(): void {
    $this->digest();
    $this->setClock('2030-01-07 08:00:00');
    $this->notify(['sendHour' => 8, 'daily' => TRUE]);
    $this->setClock('2030-01-07 23:59:59');

    $result = $this->notify(['sendHour' => 8, 'daily' => TRUE]);

    self::assertSame(['mode' => 'skipped', 'sent_on' => '2030-01-07'], $result);
    self::assertCount(1, self::spooledTo());
  }

  public function testAClaimOfAnOverlappingRunIsSeen(): void {
    $this->digest();
    $this->setClock('2030-01-07 08:00:00');
    $this->notify(['sendHour' => 8, 'daily' => TRUE]);
    $claimed = \CRM_Core_DAO::singleValueQuery('SELECT value FROM civicrm_setting WHERE name = %1', [1 => [
      SendWindow::SETTING,
      'String',
    ]]);
    // This run forgets the send; the database keeps it, as another run's write would.
    \Civi::settings()->set(SendWindow::SETTING, []);
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_setting SET value = %1 WHERE name = %2', [
      1 => [$claimed, 'String'],
      2 => [SendWindow::SETTING, 'String'],
    ]);

    self::assertSame('skipped', $this->notify(['sendHour' => 8, 'daily' => TRUE])['mode']);
    self::assertCount(1, self::spooledTo());
  }

  public function testTheNextDaySendsAgainAndForgetsTheDayBefore(): void {
    $this->digest();
    $this->setClock('2030-01-07 09:00:00');
    $this->notify(['sendHour' => 8, 'daily' => TRUE]);
    $this->setClock('2030-01-08 00:30:00');
    self::assertSame('skipped', $this->notify(['sendHour' => 8, 'daily' => TRUE])['mode']);
    $this->setClock('2030-01-08 08:00:00');

    self::assertSame('sent', $this->notify(['sendHour' => 8, 'daily' => TRUE])['mode']);
    self::assertCount(2, self::spooledTo());
    self::assertSame(['2030-01-08'], array_values(\Civi::settings()->get(SendWindow::SETTING)));
  }

  public function testEachDeliveryTargetGetsItsOwnDailySend(): void {
    $this->digest();
    $this->setClock('2030-01-07 09:00:00');
    $other = ['email_to' => 'board@example.org', 'channel' => 'email'];
    $this->notify(['sendHour' => 8, 'daily' => TRUE]);

    self::assertSame('sent', $this->notify(['sendHour' => 8, 'daily' => TRUE], $other)['mode']);
    $reordered = array_reverse($other, TRUE);
    self::assertSame('skipped', $this->notify(['sendHour' => 8, 'daily' => TRUE], $reordered)['mode']);
    self::assertSame(['ops@example.org', 'board@example.org'], self::spooledTo());
  }

  public function testAFailedRunCountsAsTheDaysSend(): void {
    $digest = $this->digest();
    \Civi\Api4\HeraldDigest::delete(checkPermissions: FALSE)
      ->addWhere('id', '=', $digest['id'])
      ->execute();
    $this->setClock('2030-01-07 09:00:00');
    try {
      $this->notify(['sendHour' => 8, 'daily' => TRUE]);
      self::fail('The digest was sent.');
    }
    catch (DigestProblem $e) {
      self::assertStringContainsString('does not exist', $e->getMessage());
    }
    $this->setClock('2030-01-07 09:15:00');

    self::assertSame('skipped', $this->notify(['sendHour' => 8, 'daily' => TRUE])['mode']);
    self::assertCount(1, self::spooledTo(), 'Only the first run sends the failure notice.');
  }

  public function testSearchesWithoutRowsSendNothing(): void {
    $this->digest(['empty', 'empty']);

    self::assertSame(['mode' => 'skipped', 'empty' => TRUE, 'sources' => 2], $this->notify(['skipEmpty' => TRUE]));
    self::assertSame([], self::spooledTo());
  }

  public function testAnEmptyDayIsDecidedOnce(): void {
    $this->digest(['empty']);
    $this->setClock('2030-01-07 09:00:00');
    self::assertSame(
      ['mode' => 'skipped', 'empty' => TRUE, 'sources' => 1],
      $this->notify(['sendHour' => 8, 'daily' => TRUE, 'skipEmpty' => TRUE]),
    );
    $this->setClock('2030-01-07 10:00:00');

    $result = $this->notify(['sendHour' => 8, 'daily' => TRUE, 'skipEmpty' => TRUE]);

    self::assertSame(['mode' => 'skipped', 'sent_on' => '2030-01-07'], $result);
    self::assertSame([], self::spooledTo());
  }

  /**
   * @return array<string, array{list<string>}>
   */
  public static function digestsWithSomethingToReport(): array {
    return [
      'one search with a row' => [['empty', 'filled']],
      'a section' => [['empty', 'section']],
      'a count of no rows' => [['empty', 'total']],
      'no sources' => [[]],
    ];
  }

  /**
   * @dataProvider digestsWithSomethingToReport
   * @param list<string> $sources
   */
  public function testADigestWithARowOrASectionIsNotEmpty(array $sources): void {
    $this->digest($sources);

    self::assertSame('sent', $this->notify(['skipEmpty' => TRUE])['mode']);
    self::assertCount(1, self::spooledTo());
  }

  public function testWithoutDailyTheSendHourIsTheOnlyHour(): void {
    $this->digest();
    $this->setClock('2030-01-07 10:00:00');
    self::assertSame(
      ['mode' => 'skipped', 'send_hour' => 8, 'current_hour' => 10],
      $this->notify(['sendHour' => 8]),
    );
    $this->setClock('2030-01-07 08:10:00');
    $this->notify(['sendHour' => 8]);
    $this->setClock('2030-01-07 08:50:00');

    self::assertSame('sent', $this->notify(['sendHour' => 8])['mode']);
    self::assertCount(2, self::spooledTo());
    self::assertSame([], \Civi::settings()->get(SendWindow::SETTING));
  }

  public function testDailyWithoutASendHourIsRejected(): void {
    $this->digest();
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('herald: daily needs a sendHour');
    $this->notify(['daily' => TRUE]);
  }

  public function testASendHourOutsideTheDayIsRejected(): void {
    $this->digest();
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('herald: sendHour must be an hour from 0 to 23, got 24');
    $this->notify(['sendHour' => 24, 'daily' => TRUE]);
  }

  public function testAJobOnEveryCronRunSendsOnce(): void {
    \Civi::settings()->set('environment', 'Production');
    $this->digest();
    $this->setClock('2030-01-07 09:00:00');
    $id = Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald daily test digest',
        'run_frequency' => 'Always',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode([
          'version' => 4,
          'checkPermissions' => FALSE,
          'digest' => self::DIGEST,
          'sendHour' => 8,
          'daily' => TRUE,
          'skipEmpty' => TRUE,
          'actionParams' => self::DELIVERY,
        ]),
      ])
      ->execute()
      ->single()['id'];

    (new \CRM_Core_JobManager())->executeJobById($id);
    (new \CRM_Core_JobManager())->executeJobById($id);

    self::assertSame(['ops@example.org'], self::spooledTo());
  }

}
