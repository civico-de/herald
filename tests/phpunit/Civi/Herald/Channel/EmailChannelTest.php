<?php

namespace Civi\Herald\Channel;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class EmailChannelTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /** @var array[] Mail params captured by the injected mailer. */
  private array $sentMails = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->sentMails = [];
    \Civi::settings()->set('herald_email_from', '"Herald" <noreply@example.org>');
    \Civi::settings()->set('herald_email_default_to', '');
  }

  private function makeChannel(bool $mailerResult = TRUE): EmailChannel {
    return new EmailChannel(function (array $params) use ($mailerResult): bool {
      $this->sentMails[] = $params;
      return $mailerResult;
    });
  }

  public function testSendsExpectedParams(): void {
    $channel = $this->makeChannel();

    $channel->send('<p>hello</p>', [
      'email_to' => 'alerts@example.org',
      'email_subject' => 'Contribution completed',
    ]);

    $this->assertCount(1, $this->sentMails);
    $mail = $this->sentMails[0];
    $this->assertSame('"Herald" <noreply@example.org>', $mail['from']);
    $this->assertSame('alerts@example.org', $mail['toEmail']);
    $this->assertSame('Contribution completed', $mail['subject']);
    $this->assertSame('<p>hello</p>', $mail['html']);
    $this->assertArrayNotHasKey('text', $mail);
  }

  public function testEmptyToFallsBackToDefaultSetting(): void {
    \Civi::settings()->set('herald_email_default_to', 'fallback@example.org');
    $channel = $this->makeChannel();

    $channel->send('hi', ['email_to' => '']);

    $this->assertSame('fallback@example.org', $this->sentMails[0]['toEmail']);
  }

  public function testThrowsWhenRecipientMissing(): void {
    $channel = $this->makeChannel();

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/email_to/');
    $channel->send('hi', []);
  }

  public function testEmptyFromSettingFallsBackToSiteDefault(): void {
    \Civi::settings()->set('herald_email_from', '');
    $channel = $this->makeChannel();

    $channel->send('hi', ['email_to' => 'a@example.org']);

    // Headless site default — exact value comes from the test DB domain.
    $this->assertNotSame('', (string) $this->sentMails[0]['from']);
    $this->assertStringContainsString('@', (string) $this->sentMails[0]['from']);
  }

  public function testThrowsWhenMailerReportsFailure(): void {
    $channel = $this->makeChannel(FALSE);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/delivery failed/');
    $channel->send('hi', ['email_to' => 'a@example.org']);
  }

  public function testTextFormatPutsBodyInTextPart(): void {
    $channel = $this->makeChannel();

    $channel->send('plain body', ['email_to' => 'a@example.org', 'email_format' => 'text']);

    $mail = $this->sentMails[0];
    $this->assertSame('plain body', $mail['text']);
    $this->assertArrayNotHasKey('html', $mail);
  }

  public function testForwardsCcAndBcc(): void {
    $channel = $this->makeChannel();

    $channel->send('hi', [
      'email_to' => 'a@example.org',
      'email_cc' => 'b@example.org, c@example.org',
      'email_bcc' => 'd@example.org',
    ]);

    $mail = $this->sentMails[0];
    $this->assertSame('b@example.org, c@example.org', $mail['cc']);
    $this->assertSame('d@example.org', $mail['bcc']);
  }

}
