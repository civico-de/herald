<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Job;
use Civi\Api4\MessageTemplate;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A scheduled digest job with one template mails under the template's
 * subject unless the job names its own.
 *
 * @group headless
 */
class DigestJobSubjectTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const WORKFLOW = 'herald_test_digest_subject';

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    \Civi::settings()->set('mailing_backend', [
      'outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB,
    ]);
    \Civi::settings()->set('herald_email_from', '"Herald" <noreply@example.org>');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
    MessageTemplate::create(checkPermissions: FALSE)
      ->addValue('msg_title', 'Herald test digest subject')
      ->addValue('workflow_name', self::WORKFLOW)
      ->addValue('msg_subject', 'Digest with {$totals.count} contributions')
      ->addValue('msg_html', 'count {$totals.count}')
      ->addValue('is_default', value: TRUE)
      ->addValue('is_active', value: TRUE)
      ->execute();
  }

  /**
   * Runs the digest the way cron does: a scheduled APIv4 job through the JobManager.
   *
   * @param array<string, mixed> $extra
   */
  private function runJob(array $extra = []): string {
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
          'actionParams' => $extra
            + ['template' => self::WORKFLOW, 'channel' => 'email', 'email_to' => 'ops@example.org'],
        ]),
      ])
      ->execute()
      ->single()['id'];
    (new \CRM_Core_JobManager())->executeJobById($id);
    return (string) \CRM_Core_DAO::singleValueQuery('SELECT headers FROM civicrm_mailing_spool');
  }

  public function testSendHourOutsideTheHourSkips(): void {
    $result = \Civi\Api4\Herald::notifyDigest(checkPermissions: FALSE)
      ->setSendHour(((int) date('G') + 12) % 24)
      ->setActionParams(['template' => self::WORKFLOW, 'channel' => 'email', 'email_to' => 'ops@example.org'])
      ->execute()
      ->single();
    $this->assertSame('skipped', $result['mode']);
    $this->assertSame('0', (string) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_spool'));
  }

  public function testDuringTheSendHourItSends(): void {
    $zone = \CRM_Core_Config::singleton()->userSystem->getTimeZoneString() ?: date_default_timezone_get();
    $result = \Civi\Api4\Herald::notifyDigest(checkPermissions: FALSE)
      ->setSendHour((int) (new \DateTime('now', new \DateTimeZone($zone)))->format('G'))
      ->setActionParams(['template' => self::WORKFLOW, 'channel' => 'email', 'email_to' => 'ops@example.org'])
      ->execute()
      ->single();
    $this->assertSame('sent', $result['mode']);
  }

  public function testAnAggregatedDigestCarriesNoAmount(): void {
    \Civi\Api4\Herald::notifyDigest(checkPermissions: FALSE)
      ->setActionParams([
        'template' => self::WORKFLOW,
        'channel' => 'email',
        'email_to' => 'ops@example.org',
        'aggregate_minutes' => 5,
      ])
      ->execute();
    $this->assertSame(
      1,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer WHERE amount IS NULL'),
    );
  }

  public function testAMigratedOldJobRunsOutsideProduction(): void {
    \Civi::settings()->set('environment', 'Development');
    // Switching to Development disables outbound mail; the spool stands in for it.
    \Civi::settings()->set('mailing_backend', [
      'outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB,
    ]);
    $id = Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald test old digest',
        'run_frequency' => 'Hourly',
        'api_entity' => 'Job',
        'api_action' => 'herald_contribution_digest',
        'parameters' => implode("\n", [
          'template=' . self::WORKFLOW,
          'channel=email',
          'email_to=ops@example.org',
          'show_pending=0',
          'goal=500',
          'runInNonProductionEnvironment=1',
        ]),
      ])
      ->execute()
      ->single()['id'];
    \CRM_Herald_Upgrader_DigestJobs::migrate();

    (new \CRM_Core_JobManager())->executeJobById($id);

    $this->assertStringContainsString(
      'Subject: Digest with 0 contributions',
      (string) \CRM_Core_DAO::singleValueQuery('SELECT headers FROM civicrm_mailing_spool'),
    );
  }

  public function testTemplateSubjectIsTheDefault(): void {
    $this->assertStringContainsString('Subject: Digest with 0 contributions', $this->runJob());
  }

  public function testExplicitSubjectWins(): void {
    $this->assertStringContainsString('Subject: Morning numbers', $this->runJob([
      'email_subject' => 'Morning numbers',
    ]));
  }

}
