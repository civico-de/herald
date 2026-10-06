<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\MessageTemplate;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Job.herald_contribution_digest with one template mails under the template's
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
   * @param array<string, mixed> $extra
   */
  private function runJob(array $extra = []): string {
    civicrm_api3(
      'Job',
      'herald_contribution_digest',
      $extra
      + [
        'template' => self::WORKFLOW,
        'channel' => 'email',
        'email_to' => 'ops@example.org',
        'force' => 1,
      ],
    );
    return (string) \CRM_Core_DAO::singleValueQuery('SELECT headers FROM civicrm_mailing_spool');
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
