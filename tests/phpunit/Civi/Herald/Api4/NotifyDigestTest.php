<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4;

use Civi\Api4\Herald;
use Civi\Api4\Job;
use Civi\Herald\Digest\DigestFixture;
use Civi\Herald\Digest\DigestProblem;
use Civi\Herald\Schedule\Clock;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A digest goes out under its template's subject; one that cannot be composed
 * reaches its recipients as a short notice, and the error stays an error.
 *
 * @group headless
 */
class NotifyDigestTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const DIGEST = 'herald_test_notify';

  private const DELIVERY = ['channel' => 'email', 'email_to' => 'ops@example.org'];

  /**
   * @var array{id: int, template_id: int}
   */
  private array $digest;

  private ?\Throwable $sectionError = NULL;

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
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
    $this->digest = DigestFixture::create(
      self::DIGEST,
      'Herald test digest',
      '<p>{$seats.booked} seats</p>',
      [['name' => 'seats', 'type' => 'section', 'section_key' => 'test.seats']],
      'Seats: {$seats.booked}',
    );
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
    if ($this->sectionError !== NULL) {
      throw $this->sectionError;
    }
    $sections['test.seats'] = ['booked' => 138];
  }

  /**
   * @param array<string, mixed> $delivery
   * @return array<string, mixed>
   */
  private function notify(array $delivery = self::DELIVERY, string $digest = self::DIGEST): array {
    return Herald::notifyDigest(checkPermissions: FALSE)
      ->setDigest($digest)
      ->setActionParams($delivery)
      ->execute()
      ->single();
  }

  /**
   * Subject and body of each spooled mail, MIME-decoded.
   *
   * @return list<array{subject: string, body: string}>
   */
  private static function spooled(): array {
    $mails = [];
    $dao = \CRM_Core_DAO::executeQuery('SELECT headers, body FROM civicrm_mailing_spool ORDER BY id');
    while ($dao->fetch()) {
      // mb_decode_mimeheader() unfolds every line into one, so the line is found first.
      preg_match('/^Subject: (.*)$/m', (string) $dao->headers, $subject);
      $mails[] = [
        'subject' => mb_decode_mimeheader(trim($subject[1] ?? '')),
        'body' => quoted_printable_decode((string) $dao->body),
      ];
    }
    return $mails;
  }

  /**
   * The error the action raises, after it told the recipients.
   */
  private function failure(callable $notify): \Throwable {
    try {
      $notify();
    }
    catch (\Throwable $e) {
      return $e;
    }
    self::fail('The digest was sent.');
  }

  public function testACronJobSendsTheFilledTemplate(): void {
    \Civi::settings()->set('environment', 'Production');
    $id = Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald test digest',
        'run_frequency' => 'Hourly',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode([
          'version' => 4,
          'checkPermissions' => FALSE,
          'digest' => self::DIGEST,
          'actionParams' => self::DELIVERY,
        ]),
      ])
      ->execute()
      ->single()['id'];

    (new \CRM_Core_JobManager())->executeJobById($id);

    $mails = self::spooled();
    self::assertSame(['Seats: 138'], array_column($mails, 'subject'));
    self::assertStringContainsString('138 seats', $mails[0]['body']);
  }

  public function testTheResultNamesTheModeAndTheSources(): void {
    self::assertSame(['mode' => 'sent', 'sources' => 1], $this->notify());
  }

  public function testSendHourOutsideTheHourSkips(): void {
    $this->setClock('2030-01-07 07:59:00');
    $result = Herald::notifyDigest(checkPermissions: FALSE)
      ->setSendHour(8)
      ->setDigest(self::DIGEST)
      ->setActionParams(self::DELIVERY)
      ->execute()
      ->single();
    self::assertSame(['mode' => 'skipped', 'send_hour' => 8, 'current_hour' => 7], $result);
    self::assertSame([], self::spooled());
  }

  public function testDuringTheSendHourItSends(): void {
    $this->setClock('2030-01-07 08:59:00');
    $result = Herald::notifyDigest(checkPermissions: FALSE)
      ->setSendHour(8)
      ->setDigest(self::DIGEST)
      ->setActionParams(self::DELIVERY)
      ->execute()
      ->single();
    self::assertSame('sent', $result['mode']);
  }

  public function testASendHourOutsideTheDayIsRejected(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('herald: sendHour must be an hour from 0 to 23, got 25');
    Herald::notifyDigest(checkPermissions: FALSE)
      ->setSendHour(25)
      ->setDigest(self::DIGEST)
      ->setActionParams(self::DELIVERY)
      ->execute();
  }

  /**
   * Local wall time in CiviCRM's timezone.
   */
  private function setClock(string $local): void {
    \CRM_Utils_Time::setTime((new \DateTimeImmutable($local, Clock::zone()))->getTimestamp());
  }

  public function testAnAggregatedDigestCarriesNoAmount(): void {
    $this->notify(self::DELIVERY + ['aggregate_minutes' => 5]);
    self::assertSame(
      1,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer WHERE amount IS NULL'),
    );
  }

  /**
   * @return array<string, array{array<string, mixed>}>
   */
  public static function deliveriesWithASubject(): array {
    return [
      'email_subject' => [self::DELIVERY + ['email_subject' => 'Morning numbers']],
      'in channel_config' => [
        self::DELIVERY + ['channel_config' => json_encode(['email_subject' => 'Morning numbers'])],
      ],
    ];
  }

  /**
   * @dataProvider deliveriesWithASubject
   * @param array<string, mixed> $delivery
   */
  public function testASubjectInTheDeliverySettingsIsRefused(array $delivery): void {
    $e = $this->failure(fn () => $this->notify($delivery));

    self::assertInstanceOf(DigestProblem::class, $e);
    self::assertStringContainsString('A digest takes its subject from its message template', $e->getMessage());
    $mails = self::spooled();
    self::assertCount(1, $mails);
    self::assertStringContainsString('could not be composed', $mails[0]['body']);
  }

  public function testADigestThatCannotBeComposedTellsItsRecipients(): void {
    \Civi\Api4\MessageTemplate::delete(checkPermissions: FALSE)
      ->addWhere('id', '=', $this->digest['template_id'])
      ->execute();

    $e = $this->failure($this->notify(...));

    self::assertInstanceOf(DigestProblem::class, $e);
    $mails = self::spooled();
    self::assertSame(['Digest "Herald test digest" failed'], array_column($mails, 'subject'));
    self::assertStringContainsString('could not be composed: Digest', $mails[0]['body']);
    self::assertStringContainsString('has no message template; choose one in the digest form.', $mails[0]['body']);
  }

  public function testAnUnknownDigestTellsItsRecipientsByName(): void {
    $e = $this->failure(fn () => $this->notify(digest: 'missing'));

    self::assertSame('Digest "missing" does not exist.', $e->getMessage());
    self::assertSame(['Digest "missing" failed'], array_column(self::spooled(), 'subject'));
  }

  public function testAnErrorOfAnotherKindIsNotQuoted(): void {
    $this->sectionError = new \RuntimeException('donor 4711 has no seat');

    $e = $this->failure($this->notify(...));

    self::assertSame($this->sectionError, $e);
    $body = self::spooled()[0]['body'];
    self::assertStringContainsString('an unexpected error, see the CiviCRM log.', $body);
    self::assertStringNotContainsString('4711', $body);
  }

  public function testANoticeThatCannotBeDeliveredLeavesTheProblemRaised(): void {
    \Civi\Api4\MessageTemplate::delete(checkPermissions: FALSE)
      ->addWhere('id', '=', $this->digest['template_id'])
      ->execute();

    $e = $this->failure(fn () => $this->notify(['channel' => 'email']));

    self::assertInstanceOf(DigestProblem::class, $e);
    self::assertSame([], self::spooled());
  }

}
