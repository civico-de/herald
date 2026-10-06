<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4;

use Civi\Api4\Herald;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Herald.testTelegram hands one message to the named channel; the email
 * channel's spool makes the delivery observable.
 *
 * @group headless
 */
class TestTelegramTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    \Civi::settings()->set('mailing_backend', [
      'outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB,
    ]);
    \Civi::settings()->set('herald_email_from', '"Herald" <noreply@example.org>');
    \Civi::settings()->set('herald_email_default_to', 'ops@example.org');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
  }

  private function spooledBody(): string {
    return (string) \CRM_Core_DAO::singleValueQuery('SELECT body FROM civicrm_mailing_spool');
  }

  public function testSendsTheMessageThroughTheChannelGiven(): void {
    $result = Herald::testTelegram(checkPermissions: FALSE)
      ->setChannel('email')
      ->setMessage('<b>hello</b>')
      ->execute()
      ->first();

    $this->assertStringContainsString('<b>hello</b>', $this->spooledBody());
    $this->assertSame(['channel' => 'email', 'status' => 'sent', 'message_length' => 12], $result);
  }

  public function testWithoutMessageSendsTheSmokeText(): void {
    Herald::testTelegram(checkPermissions: FALSE)->setChannel('email')->execute();

    $this->assertStringContainsString('herald smoke test', $this->spooledBody());
  }

  public function testUnknownChannelFails(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('"no_such_channel" is not registered');
    Herald::testTelegram(checkPermissions: FALSE)->setChannel('no_such_channel')->execute();
  }

}
