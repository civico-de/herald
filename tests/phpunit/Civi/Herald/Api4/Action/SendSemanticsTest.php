<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Herald\Dispatch\ChannelSubject;
use PHPUnit\Framework\TestCase;

/**
 * The semantic-to-config-key translation, in isolation: callers pass semantic
 * keys (subject, format), never channel-specific config keys.
 *
 * @group unit
 */
class SendSemanticsTest extends TestCase {

  /**
   * @var array<string, string> */
  private const EMAIL_FIELDS = ['subject' => 'email_subject', 'format' => 'email_format'];

  public function testSubjectAndFormatAreMappedOntoTheChannelKeys(): void {
    $config = ChannelSubject::withSemantics([], self::EMAIL_FIELDS, 'Alert!', 'text');
    $this->assertSame(['email_subject' => 'Alert!', 'email_format' => 'text'], $config);
  }

  public function testChannelWithoutTheSemanticDropsIt(): void {
    // Telegram declares neither subject nor format.
    $config = ChannelSubject::withSemantics(['chat_id' => '42'], [], 'Alert!', 'text');
    $this->assertSame(['chat_id' => '42'], $config);
  }

  public function testChannelWithOnlySubjectIgnoresFormat(): void {
    $config = ChannelSubject::withSemantics([], ['subject' => 'ntfy_title'], 'Alert!', 'text');
    $this->assertSame(['ntfy_title' => 'Alert!'], $config);
  }

  public function testExplicitConfigKeyWins(): void {
    $config = ChannelSubject::withSemantics(['email_subject' => 'explicit'], self::EMAIL_FIELDS, 'semantic', '');
    $this->assertSame('explicit', $config['email_subject']);
  }

  public function testEmptySemanticValuesAreNotWritten(): void {
    $config = ChannelSubject::withSemantics(['email_to' => 'a@example.org'], self::EMAIL_FIELDS, '', '');
    $this->assertSame(['email_to' => 'a@example.org'], $config);
  }

  public function testOtherConfigKeysArePreserved(): void {
    $config = ChannelSubject::withSemantics(
      ['email_to' => 'a@example.org', 'email_cc' => 'b@example.org'],
      self::EMAIL_FIELDS,
      'S',
      'html',
    );
    $this->assertSame(
      ['email_to' => 'a@example.org', 'email_cc' => 'b@example.org', 'email_subject' => 'S', 'email_format' => 'html'],
      $config,
    );
  }

}
