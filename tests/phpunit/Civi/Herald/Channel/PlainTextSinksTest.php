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

  public function testFromHtmlBreaksBlocksAndKeepsPre(): void {
    $this->assertSame("a\nb", PlainText::fromHtml('<p>a</p><p>b</p>'));
    $this->assertSame("a\n\nb", PlainText::fromHtml("<p>a</p>\n\n<p>b</p>"));
    $this->assertSame("a\nb", PlainText::fromHtml('<div>a</div><div>b</div>'));
    $this->assertSame("- x\n- y", PlainText::fromHtml("<ul>\n<li>x</li>\n<li>y</li>\n</ul>"));
    $this->assertSame("T\ncell 1\ncell 2", PlainText::fromHtml('<h2>T</h2><table><tr><td>cell 1</td></tr><tr><td>cell 2</td></tr></table>'));
    $this->assertSame("a\n\nb", PlainText::fromHtml("\n a\n\n\n\nb\n"));
    $this->assertSame("x\n1\n\n\n\n2", PlainText::fromHtml("x\n<pre>1\n\n\n\n2</pre>"));
  }

  /**
   * A plain-text body travels as its escaped form: text sinks get the text
   * back, HTML sinks show it instead of parsing it.
   */
  public function testTextBodyRoundTripsAndIsShownInHtmlSinks(): void {
    $text = "Wert <5 erwartet & &amp; <b>kein Tag</b>\nZeile 2";
    $body = PlainText::toHtml($text);
    $this->assertSame($text, PlainText::fromHtml($body));
    $this->assertSame($body, TelegramText::forParseMode($body, 'HTML'));
    $this->assertSame(
      "Wert &lt;5 erwartet &amp; &amp;amp; &lt;b&gt;kein Tag&lt;/b&gt;<br>\nZeile 2",
      EmailChannel::buildMailParams($body, ['email_to' => 'a@example.org'], 'f@example.org')['html'],
    );
    $this->assertSame($text, EmailChannel::buildMailParams($body, ['email_to' => 'a@example.org', 'email_format' => 'text'], 'f@example.org')['text']);
    $this->assertSame($text, WebhookChannel::buildPayload($body, 'text')['data']);
    $this->assertSame($text, PushoverChannel::buildForm('t', 'u', $body, [])['message']);
  }

  public function testHtmlLineBreaksLeavePreAndBlockTagsAlone(): void {
    $this->assertSame(
      "<b>A</b><br>\n<br>\nB<br>\n<pre>1\n2</pre>\nC",
      HtmlLineBreaks::apply("<b>A</b>\n\nB\n<pre>1\n2</pre>\nC"),
    );
    $this->assertSame("<p>a</p>\n<ul>\n<li>i</li>\n</ul>\nx<br />\ny", HtmlLineBreaks::apply("<p>a</p>\n<ul>\n<li>i</li>\n</ul>\nx<br />\ny"));
  }

  public function testEmailTextPartIsPlainAndHtmlPartKeepsLineBreaks(): void {
    $to = ['email_to' => 'a@example.org'];
    $this->assertSame(
      "🔴 <b>Check</b>: Tom &amp; Jerry &lt;x&gt;<br>\n<pre>a | b</pre>",
      EmailChannel::buildMailParams(self::BODY, $to, 'f@example.org')['html'],
    );
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
    $this->assertSame(
      "🔴 <b>Check</b>: Tom &amp; Jerry &lt;x&gt;<br>\n<pre>a | b</pre>",
      ActivityText::details(self::BODY, 'html'),
    );
    $this->assertSame('🔴 Check: Tom & Jerry <x> a | b', ActivityText::subject(self::BODY));
  }

}
