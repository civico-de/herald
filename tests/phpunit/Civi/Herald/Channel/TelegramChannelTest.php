<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class TelegramChannelTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var \GuzzleHttp\Psr7\Request[] */
  private array $capturedRequests = [];

  private string $botToken;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->capturedRequests = [];
    $this->botToken = bin2hex(random_bytes(8));
  }

  /**
   * Send with the bot token a configured channel adds to every delivery.
   */
  private function send(TelegramChannel $channel, string $body, array $config): void {
    $channel->send($body, $config + ['bot_token' => $this->botToken]);
  }

  private function makeMockedClient(Response $response): Client {
    $mock = new MockHandler([$response]);
    $stack = HandlerStack::create($mock);
    $stack->push(\GuzzleHttp\Middleware::history($this->capturedRequests));
    return new Client(['handler' => $stack]);
  }

  public function testSendsExpectedPayload(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true,"result":{}}'));
    $channel = new TelegramChannel($client);

    $this->send($channel, '<b>hello</b>', ['chat_id' => '-100']);

    $this->assertCount(1, $this->capturedRequests);
    /** @var \GuzzleHttp\Psr7\Request $request */
    $request = $this->capturedRequests[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertStringContainsString("/bot{$this->botToken}/sendMessage", (string) $request->getUri());

    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame('-100', $body['chat_id']);
    $this->assertSame('<b>hello</b>', $body['text']);
    $this->assertSame('HTML', $body['parse_mode']);
  }

  /**
   * sendMessage answers a body over 4096 units with HTTP 400 "text is too
   * long", and in an aggregated alert that body is composed from whatever a
   * bulk operation happened to write into one window. The channel therefore
   * has to fit it before posting rather than learn the limit from the API.
   */
  public function testOversizedBodyIsShortenedBeforePosting(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    $long = implode("\n\n", array_fill(0, 893, '🎟 <b>Ticket verkauft</b>: 60,00 €'));
    $this->send($channel, $long, ['chat_id' => '-100']);

    $text = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE)['text'];
    // Telegram counts its 4096 after parsing entities, so that is the length
    // the shortened message has to satisfy.
    $this->assertLessThanOrEqual(TelegramChannel::MAX_TEXT_LENGTH, MessageLimit::htmlTextLength($text));
    $this->assertStringContainsString('shortened', $text);
    $this->assertSame(substr_count($text, '<b>'), substr_count($text, '</b>'), 'markup stays parseable');
  }

  /**
   * The marker is translated, so its punctuation is whatever a translator
   * wrote — and in MarkdownV2 an unescaped "." is a syntax error. Without
   * escaping, shortening would itself become the reason the message bounces.
   */
  public function testMarkerIsEscapedForMarkdownV2(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    $this->send($channel, str_repeat("line\n\n", 2000), ['chat_id' => '-100', 'parse_mode' => 'MarkdownV2']);

    $text = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE)['text'];
    $this->assertLessThanOrEqual(TelegramChannel::MAX_TEXT_LENGTH, MessageLimit::length($text));
    $marker = mb_substr($text, mb_strrpos($text, "\n\n") + 2);
    // Escaped, not stripped and not mangled: an "\\$1" replacement would emit
    // the literal backreference instead of a backslash, which reads as garbage
    // and leaves the reserved character unescaped all the same.
    $this->assertStringContainsString('\\.', $marker, 'the full stop is escaped');
    $this->assertStringNotContainsString('$1', $marker, 'the backreference is not emitted literally');
    $this->assertSame(
      substr_count($marker, '.'),
      substr_count($marker, '\\.'),
      'every reserved character carries its escape',
    );
  }

  /**
   * Telegram measures its 4096 after parsing entities, so a body whose raw
   * source is over the limit only because of markup must go out whole.
   */
  public function testMarkupHeavyBodyUnderTheParsedLimitIsPostedVerbatim(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    // 4000 rendered characters, ~28000 raw once every one carries a <b> pair.
    $marked = str_repeat('<b>x</b>', 4000);
    $this->send($channel, $marked, ['chat_id' => '-100']);

    $text = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE)['text'];
    $this->assertSame($marked, $text, 'markup does not count towards the limit');
  }

  public function testBodyAtTheLimitIsPostedVerbatim(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    $exact = str_repeat('x', TelegramChannel::MAX_TEXT_LENGTH);
    $this->send($channel, $exact, ['chat_id' => '-100']);

    $text = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE)['text'];
    $this->assertSame($exact, $text);
  }

  public function testAnEmptyDeliveryChatIdUsesTheChannelsDefaultChat(): void {
    // The action form stores chat_id even when empty; the channel's chat must apply.
    \Civi\Herald\ChannelFixture::add('ops_bot', 'telegram', ['bot_token' => $this->botToken, 'chat_id' => '-200']);
    $registry = new ChannelRegistry();
    $registry->register(new TelegramChannel($this->makeMockedClient(new Response(200, [], '{"ok":true}'))));

    (new \Civi\Herald\Dispatch\ChannelSender($registry))->send('ops_bot', 'hi', ['chat_id' => '']);

    $request = $this->capturedRequests[0]['request'];
    $this->assertStringContainsString("/bot{$this->botToken}/sendMessage", (string) $request->getUri());
    $this->assertSame('-200', json_decode((string) $request->getBody(), TRUE)['chat_id']);
  }

  public function testAnEmptyParseModeMeansHtml(): void {
    $channel = new TelegramChannel($this->makeMockedClient(new Response(200, [], '{"ok":true}')));

    $this->send($channel, 'hi', ['chat_id' => '-1', 'parse_mode' => '']);

    $this->assertSame(
      'HTML',
      json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE)['parse_mode'],
    );
  }

  public function testPlainTextOmitsParseMode(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    $this->send($channel, 'hi', ['chat_id' => '-1', 'parse_mode' => 'text']);

    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertArrayNotHasKey('parse_mode', $body);
  }

  public function testPlainTextSendsVisibleTextOfTheHtmlBody(): void {
    $channel = new TelegramChannel($this->makeMockedClient(new Response(200, [], '{"ok":true}')));

    $this->send($channel, '<b>Tom &amp; Jerry &lt;x&gt;</b>', ['chat_id' => '-1', 'parse_mode' => 'text']);

    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertSame('Tom & Jerry <x>', $body['text']);
  }

  public function testThrowsWhenTokenMissing(): void {
    $channel = new TelegramChannel($this->makeMockedClient(new Response(200, [], '{"ok":true}')));

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/no bot_token/');
    $channel->send('hi', ['chat_id' => '-1']);
  }

  public function testAFailedRequestDoesNotNameTheToken(): void {
    $mock = new MockHandler([
      static fn (\Psr\Http\Message\RequestInterface $request): \GuzzleHttp\Exception\ConnectException => new \GuzzleHttp\Exception\ConnectException(
        'cURL error 6: Could not resolve host for ' . $request->getUri(),
        $request,
      ),
    ]);
    $channel = new TelegramChannel(new Client(['handler' => HandlerStack::create($mock)]));

    try {
      $this->send($channel, 'hi', ['chat_id' => '-1']);
      self::fail('expected the send to fail');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('Could not resolve host', $e->getMessage());
      $this->assertStringNotContainsString($this->botToken, $e->getMessage());
      $this->assertNull($e->getPrevious());
    }
  }

  public function testThrowsWhenChatIdMissing(): void {
    $channel = new TelegramChannel($this->makeMockedClient(new Response(200, [], '{"ok":true}')));

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/chat_id/');
    $this->send($channel, 'hi', []);
  }

  public function testThrowsOnHttpError(): void {
    $client = $this->makeMockedClient(new Response(401, [], '{"ok":false,"description":"Unauthorized"}'));
    $channel = new TelegramChannel($client);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/HTTP 401/');
    $this->send($channel, 'hi', ['chat_id' => '-1']);
  }

  public function testThrowsWhenApiReturnsOkFalse(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":false,"description":"Bad Request"}'));
    $channel = new TelegramChannel($client);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/ok=false/');
    $this->send($channel, 'hi', ['chat_id' => '-1']);
  }

  public function testForwardsOptionalFlags(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    $this->send($channel, 'hi', [
      'chat_id' => '-1',
      'parse_mode' => 'MarkdownV2',
      'disable_notification' => TRUE,
      'disable_web_page_preview' => TRUE,
    ]);

    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertSame('MarkdownV2', $body['parse_mode']);
    $this->assertTrue($body['disable_notification']);
    $this->assertTrue($body['disable_web_page_preview']);
  }

}
