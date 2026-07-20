<?php

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

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->capturedRequests = [];
    \Civi::settings()->set('herald_telegram_bot_token', 'TEST-TOKEN');
    \Civi::settings()->set('herald_telegram_default_chat_id', '');
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

    $channel->send('<b>hello</b>', ['chat_id' => '-100']);

    $this->assertCount(1, $this->capturedRequests);
    /** @var \GuzzleHttp\Psr7\Request $request */
    $request = $this->capturedRequests[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertStringContainsString('/botTEST-TOKEN/sendMessage', (string) $request->getUri());

    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame('-100', $body['chat_id']);
    $this->assertSame('<b>hello</b>', $body['text']);
    $this->assertSame('HTML', $body['parse_mode']);
  }

  public function testFallsBackToDefaultChatId(): void {
    \Civi::settings()->set('herald_telegram_default_chat_id', '-200');
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    $channel->send('hi', []);

    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertSame('-200', $body['chat_id']);
  }

  public function testEmptyChatIdStillFallsBackToDefault(): void {
    // The action form always stores chat_id, possibly '' — the documented
    // fallback to the default setting must still kick in.
    \Civi::settings()->set('herald_telegram_default_chat_id', '-200');
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    $channel->send('hi', ['chat_id' => '']);

    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertSame('-200', $body['chat_id']);
  }

  public function testPlainTextOmitsParseMode(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    $channel->send('hi', ['chat_id' => '-1', 'parse_mode' => '']);

    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertArrayNotHasKey('parse_mode', $body);
  }

  public function testThrowsWhenTokenMissing(): void {
    \Civi::settings()->set('herald_telegram_bot_token', '');
    $channel = new TelegramChannel($this->makeMockedClient(new Response(200, [], '{"ok":true}')));

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/bot token/');
    $channel->send('hi', ['chat_id' => '-1']);
  }

  public function testThrowsWhenChatIdMissing(): void {
    $channel = new TelegramChannel($this->makeMockedClient(new Response(200, [], '{"ok":true}')));

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/chat_id/');
    $channel->send('hi', []);
  }

  public function testThrowsOnHttpError(): void {
    $client = $this->makeMockedClient(new Response(401, [], '{"ok":false,"description":"Unauthorized"}'));
    $channel = new TelegramChannel($client);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/HTTP 401/');
    $channel->send('hi', ['chat_id' => '-1']);
  }

  public function testThrowsWhenApiReturnsOkFalse(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":false,"description":"Bad Request"}'));
    $channel = new TelegramChannel($client);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/ok=false/');
    $channel->send('hi', ['chat_id' => '-1']);
  }

  public function testForwardsOptionalFlags(): void {
    $client = $this->makeMockedClient(new Response(200, [], '{"ok":true}'));
    $channel = new TelegramChannel($client);

    $channel->send('hi', [
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
