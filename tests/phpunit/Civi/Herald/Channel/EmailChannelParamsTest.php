<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for EmailChannel::buildMailParams (no Civi bootstrap).
 * The settings fallbacks and mailer wiring are covered in EmailChannelTest
 * (headless).
 *
 * @group unit
 */
class EmailChannelParamsTest extends TestCase {

  public function testHtmlIsDefaultFormat(): void {
    $params = EmailChannel::buildMailParams('<b>x</b>', ['email_to' => 'a@example.org'], 'from@example.org');
    $this->assertSame('<b>x</b>', $params['html']);
    $this->assertArrayNotHasKey('text', $params);
  }

  public function testTextFormat(): void {
    $params = EmailChannel::buildMailParams(
      'x',
      ['email_to' => 'a@example.org', 'email_format' => 'text'],
      'from@example.org',
    );
    $this->assertSame('x', $params['text']);
    $this->assertArrayNotHasKey('html', $params);
  }

  public function testDefaultSubjectWhenUnsetOrEmpty(): void {
    foreach ([[], ['email_subject' => '']] as $extra) {
      $params = EmailChannel::buildMailParams('x', $extra + ['email_to' => 'a@example.org'], 'f');
      $this->assertSame(EmailChannel::DEFAULT_SUBJECT, $params['subject']);
    }
  }

  public function testExplicitSubjectAndToName(): void {
    $params = EmailChannel::buildMailParams(
      'x',
      [
        'email_to' => 'a@example.org',
        'email_to_name' => 'Ada',
        'email_subject' => 'Hi',
      ],
      'f',
    );
    $this->assertSame('Hi', $params['subject']);
    $this->assertSame('Ada', $params['toName']);
    $this->assertSame('a@example.org', $params['toEmail']);
  }

  public function testCcBccOnlyWhenNonEmpty(): void {
    $params = EmailChannel::buildMailParams('x', ['email_to' => 'a@example.org', 'email_cc' => ''], 'f');
    $this->assertArrayNotHasKey('cc', $params);
    $this->assertArrayNotHasKey('bcc', $params);

    $params = EmailChannel::buildMailParams(
      'x',
      ['email_to' => 'a@example.org', 'email_cc' => 'c@example.org', 'email_bcc' => 'b@example.org'],
      'f',
    );
    $this->assertSame('c@example.org', $params['cc']);
    $this->assertSame('b@example.org', $params['bcc']);
  }

  public function testFromIsPassedThrough(): void {
    $params = EmailChannel::buildMailParams('x', ['email_to' => 'a@example.org'], '"N" <n@example.org>');
    $this->assertSame('"N" <n@example.org>', $params['from']);
  }

}
