<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Herald.send — the cross-extension entry point.
 *
 * The delivery test runs against the EMAIL channel with CiviCRM's outbound
 * mail redirected into civicrm_mailing_spool, so a real send is observed
 * without a byte leaving the container. The network channels (telegram, ntfy,
 * webhook, pushover) are covered by their own tests with mocked HTTP clients.
 *
 * @group headless
 */
class SendTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    // Spool outbound mail into the DB instead of handing it to an MTA.
    \Civi::settings()->set('mailing_backend', [
      'outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB,
    ]);
    \Civi::settings()->set('herald_email_from', '"Herald" <noreply@example.org>');
    \Civi::settings()->set('herald_email_default_to', '');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function spooled(): array {
    $rows = [];
    $dao = \CRM_Core_DAO::executeQuery('SELECT recipient_email, headers, body FROM civicrm_mailing_spool ORDER BY id');
    while ($dao->fetch()) {
      $rows[] = [
        'recipient_email' => $dao->recipient_email,
        'headers' => (string) $dao->headers,
        'body' => (string) $dao->body,
      ];
    }
    return $rows;
  }

  public function testSendDeliversThroughTheNamedChannel(): void {
    $result = civicrm_api4('Herald', 'send', [
      'checkPermissions' => FALSE,
      'channel' => 'email',
      'message' => 'disk usage at 94%',
      'subject' => '[ops] storage warning',
      'format' => 'text',
      'config' => ['email_to' => 'ops@example.org'],
    ]);

    $this->assertCount(1, $result);
    $row = $result->first();
    $this->assertSame('email', $row['channel']);
    $this->assertTrue($row['sent']);
    $this->assertSame(strlen('disk usage at 94%'), $row['message_length']);
    $this->assertSame([], $row['unknown_config_keys']);

    $spooled = $this->spooled();
    $this->assertCount(1, $spooled, 'exactly one mail should have been spooled');
    $this->assertSame('ops@example.org', $spooled[0]['recipient_email']);
    $this->assertStringContainsString('[ops] storage warning', $spooled[0]['headers']);
    $this->assertStringContainsString('disk usage at 94%', $spooled[0]['body']);
  }

  /**
   * The semantic params are the point of the action: a caller says "subject",
   * not "email_subject", and never learns the channel's key names.
   */
  public function testSubjectIsMappedOntoTheChannelsOwnConfigKey(): void {
    civicrm_api4('Herald', 'send', [
      'checkPermissions' => FALSE,
      'channel' => 'email',
      'message' => 'body text',
      'subject' => 'mapped subject',
      'config' => ['email_to' => 'ops@example.org'],
    ]);

    $spooled = $this->spooled();
    $this->assertStringContainsString('mapped subject', $spooled[0]['headers']);
  }

  public function testFormatTextProducesAPlainTextPart(): void {
    civicrm_api4('Herald', 'send', [
      'checkPermissions' => FALSE,
      'channel' => 'email',
      'message' => 'plain <not markup>',
      'subject' => 's',
      'format' => 'text',
      'config' => ['email_to' => 'ops@example.org'],
    ]);

    $headers = $this->spooled()[0]['headers'];
    $this->assertStringContainsString('text/plain', $headers);
    $this->assertStringNotContainsString('text/html', $headers);
  }

  public function testUnknownConfigKeysAreReportedButStillPassedThrough(): void {
    $row = civicrm_api4('Herald', 'send', [
      'checkPermissions' => FALSE,
      'channel' => 'email',
      'message' => 'body',
      'config' => ['email_to' => 'ops@example.org', 'ntfy_title' => 'wrong channel'],
    ])->first();

    $this->assertSame(['ntfy_title'], $row['unknown_config_keys']);
    // Reported, not rejected: delivery still happened.
    $this->assertTrue($row['sent']);
    $this->assertCount(1, $this->spooled());
  }

  public function testUnknownChannelThrows(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/not registered/');
    civicrm_api4('Herald', 'send', [
      'checkPermissions' => FALSE,
      'channel' => 'carrier-pigeon',
      'message' => 'body',
    ]);
  }

  public function testMissingMessageThrows(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/"message" parameter is required/');
    civicrm_api4('Herald', 'send', [
      'checkPermissions' => FALSE,
      'channel' => 'email',
      'config' => ['email_to' => 'ops@example.org'],
    ]);
  }

  public function testMissingChannelThrows(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/"channel" parameter is required/');
    civicrm_api4('Herald', 'send', [
      'checkPermissions' => FALSE,
      'message' => 'body',
    ]);
  }

  public function testInvalidFormatThrows(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/"format" must be/');
    civicrm_api4('Herald', 'send', [
      'checkPermissions' => FALSE,
      'channel' => 'email',
      'message' => 'body',
      'format' => 'rtf',
      'config' => ['email_to' => 'ops@example.org'],
    ]);
  }

  /**
   * Regression guard for the runtime @var parsing in Civi\Api4 action classes:
   * a generic like `@var array<string, mixed>` on $config makes core reject the
   * call with "Unknown parameter type" before _run() is ever reached.
   */
  public function testGetActionsDescribesTheParameters(): void {
    $action = civicrm_api4('Herald', 'getActions', [
      'checkPermissions' => FALSE,
      'where' => [['name', '=', 'send']],
    ])->single();

    foreach (['channel', 'message', 'config', 'subject', 'format'] as $name) {
      $this->assertArrayHasKey(
        $name,
        $action['params'],
        sprintf('Herald.send should expose the "%s" parameter', $name),
      );
    }
    // The exact symptom of a generic `@var` on an action property: core fails
    // to type the parameter and the whole call dies with "Unknown parameter
    // type" before _run() runs.
    $this->assertSame(['array'], $action['params']['config']['type']);
    $this->assertSame(['string'], $action['params']['channel']['type']);
  }

}
