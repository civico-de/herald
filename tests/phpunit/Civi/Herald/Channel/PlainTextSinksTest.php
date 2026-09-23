<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use PHPUnit\Framework\TestCase;

/**
 * A rendered body is HTML: the var "Tom & Jerry <x>" arrives escaped inside
 * template markup. Sinks that render HTML get it unchanged; every other sink
 * gets the text a reader would see.
 *
 * @group unit
 */
class PlainTextSinksTest extends TestCase {

  private const BODY = "🔴 <b>Check</b>: Tom &amp; Jerry &lt;x&gt;\n<pre>a | b</pre>";

  private const TEXT = "🔴 Check: Tom & Jerry <x>\na | b";

  private const HEALTHY_BODY = "✅ <b>Donation received</b>: 42,50 €\nSource: Shop";

  private const HEALTHY_TEXT = "✅ Donation received: 42,50 €\nSource: Shop";

  public function testFromHtmlDropsTagsDecodesEntitiesAndKeepsLineBreaks(): void {
    $this->assertSame(self::TEXT, PlainText::fromHtml(self::BODY));
    $this->assertSame(self::HEALTHY_TEXT, PlainText::fromHtml(self::HEALTHY_BODY));
    $this->assertSame("a\nb\nc", PlainText::fromHtml('a<br>b<BR />c'));
  }

  public function testTelegramParseModes(): void {
    $this->assertSame(self::BODY, TelegramText::forParseMode(self::BODY, 'HTML'));
    $this->assertSame(self::TEXT, TelegramText::forParseMode(self::BODY, ''));
    $this->assertSame(self::HEALTHY_TEXT, TelegramText::forParseMode(self::HEALTHY_BODY, ''));
    // Every MarkdownV2-reserved character is escaped, so the message parses.
    $this->assertSame(
      "✅ Donation received: 42,50 €\nSource: Shop \\(web\\)\\.",
      TelegramText::forParseMode(self::HEALTHY_BODY . ' (web).', 'MarkdownV2'),
    );
    $this->assertSame("Tom & Jerry <x\\>", TelegramText::forParseMode('Tom &amp; Jerry &lt;x&gt;', 'MarkdownV2'));
  }

  public function testEmailTextPartIsPlainAndHtmlPartUnchanged(): void {
    $to = ['email_to' => 'a@example.org'];
    $this->assertSame(self::BODY, EmailChannel::buildMailParams(self::BODY, $to, 'f@example.org')['html']);
    $text = EmailChannel::buildMailParams(self::BODY, $to + ['email_format' => 'text'], 'f@example.org');
    $this->assertSame(self::TEXT, $text['text']);
  }

  public function testPushoverSendsTextUnlessHtmlIsEnabled(): void {
    $this->assertSame(self::TEXT, PushoverChannel::buildForm('t', 'u', self::BODY, [])['message']);
    $this->assertSame(self::HEALTHY_TEXT, PushoverChannel::buildForm('t', 'u', self::HEALTHY_BODY, [])['message']);
    $this->assertSame(self::BODY, PushoverChannel::buildForm('t', 'u', self::BODY, ['pushover_html' => 1])['message']);
  }

  public function testWebhookFormats(): void {
    $this->assertSame(['type' => 'raw', 'data' => self::TEXT], WebhookChannel::buildPayload(self::BODY, 'text'));
    $this->assertSame(['content' => self::TEXT], WebhookChannel::buildPayload(self::BODY, 'discord')['data']);
    $this->assertSame(
      ['text' => "🔴 Check: Tom &amp; Jerry &lt;x&gt;\na | b"],
      WebhookChannel::buildPayload(self::BODY, 'slack')['data'],
    );
    $this->assertSame(['text' => self::BODY], WebhookChannel::buildPayload(self::BODY, 'json')['data']);
    $this->assertSame(['content' => self::HEALTHY_TEXT], WebhookChannel::buildPayload(self::HEALTHY_BODY, 'discord')['data']);
  }

  public function testActivityTextIsEscapedOnceAndHtmlKept(): void {
    $this->assertSame(
      "🔴 Check: Tom &amp; Jerry &lt;x&gt;<br />\na | b",
      ActivityText::details(self::BODY, 'text'),
    );
    $this->assertSame(self::BODY, ActivityText::details(self::BODY, 'html'));
    $this->assertSame('🔴 Check: Tom & Jerry <x> a | b', ActivityText::subject(self::BODY));
  }

}
