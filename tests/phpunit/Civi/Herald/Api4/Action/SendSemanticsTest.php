<?php

namespace Civi\Herald\Api4\Action;

use PHPUnit\Framework\TestCase;

/**
 * The semantic-to-config-key translation, in isolation. This is the piece that
 * used to live as hardcoded key literals in the CALLING extension.
 *
 * @group unit
 */
class SendSemanticsTest extends TestCase {

  /**
   * @var array<string, string> */
  private const EMAIL_FIELDS = ['subject' => 'email_subject', 'format' => 'email_format'];

  public function testSubjectAndFormatAreMappedOntoTheChannelKeys(): void {
    $config = Send::applySemantics([], self::EMAIL_FIELDS, 'Alert!', 'text');
    $this->assertSame(['email_subject' => 'Alert!', 'email_format' => 'text'], $config);
  }

  public function testChannelWithoutTheSemanticDropsIt(): void {
    // Telegram declares neither subject nor format.
    $config = Send::applySemantics(['chat_id' => '42'], [], 'Alert!', 'text');
    $this->assertSame(['chat_id' => '42'], $config);
  }

  public function testChannelWithOnlySubjectIgnoresFormat(): void {
    $config = Send::applySemantics([], ['subject' => 'ntfy_title'], 'Alert!', 'text');
    $this->assertSame(['ntfy_title' => 'Alert!'], $config);
  }

  public function testExplicitConfigKeyWins(): void {
    $config = Send::applySemantics(['email_subject' => 'explicit'], self::EMAIL_FIELDS, 'semantic', '');
    $this->assertSame('explicit', $config['email_subject']);
  }

  public function testEmptySemanticValuesAreNotWritten(): void {
    $config = Send::applySemantics(['email_to' => 'a@example.org'], self::EMAIL_FIELDS, '', '');
    $this->assertSame(['email_to' => 'a@example.org'], $config);
  }

  public function testOtherConfigKeysArePreserved(): void {
    $config = Send::applySemantics(['email_to' => 'a@example.org', 'email_cc' => 'b@example.org'], self::EMAIL_FIELDS, 'S', 'html');
    $this->assertSame(
      ['email_to' => 'a@example.org', 'email_cc' => 'b@example.org', 'email_subject' => 'S', 'email_format' => 'html'],
      $config
    );
  }

}
