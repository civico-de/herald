<?php

namespace Civi\Herald\Channel;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class WebhookChannelTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /** @var Request[] */
  private array $capturedRequests = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->capturedRequests = [];
    \Civi::settings()->set('herald_webhook_default_url', '');
    \Civi::settings()->set('herald_webhook_default_auth_token', '');
  }

  private function makeMockedClient(Response $response): Client {
    $mock = new MockHandler([$response]);
    $stack = HandlerStack::create($mock);
    $stack->push(\GuzzleHttp\Middleware::history($this->capturedRequests));
    return new Client(['handler' => $stack]);
  }

  public function testTextBodySentRaw(): void {
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('hello world', ['webhook_url' => 'https://example.com/hook']);

    $req = $this->capturedRequests[0]['request'];
    $this->assertSame('POST', $req->getMethod());
    $this->assertSame('https://example.com/hook', (string) $req->getUri());
    $this->assertSame('hello world', (string) $req->getBody());
    $this->assertStringContainsString('text/plain', $req->getHeaderLine('Content-Type'));
  }

  public function testSlackFormatWrapsAsJson(): void {
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('hi', [
      'webhook_url' => 'https://hooks.slack.com/services/X',
      'webhook_body_format' => 'slack',
    ]);
    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertSame(['text' => 'hi'], $body);
  }

  public function testDiscordFormatUsesContentKey(): void {
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('hi', [
      'webhook_url' => 'https://discord.com/api/webhooks/X',
      'webhook_body_format' => 'discord',
    ]);
    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertSame(['content' => 'hi'], $body);
  }

  public function testCustomJsonKey(): void {
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('hi', [
      'webhook_url' => 'https://example.com/x',
      'webhook_body_format' => 'json',
      'webhook_body_key' => 'message',
    ]);
    $this->assertSame(['message' => 'hi'], json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE));
  }

  public function testBearerTokenFromConfig(): void {
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('x', [
      'webhook_url' => 'https://example.com/x',
      'webhook_auth_token' => 'abc123',
    ]);
    $this->assertSame('Bearer abc123', $this->capturedRequests[0]['request']->getHeaderLine('Authorization'));
  }

  public function testDefaultTokenPairsWithDefaultUrl(): void {
    \Civi::settings()->set('herald_webhook_default_url', 'https://example.com/default');
    \Civi::settings()->set('herald_webhook_default_auth_token', 'from-setting');
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('x', []);
    $this->assertSame('Bearer from-setting', $this->capturedRequests[0]['request']->getHeaderLine('Authorization'));
  }

  public function testDefaultTokenNotSentToConfigSuppliedUrl(): void {
    // The default token belongs to the default endpoint. A caller-supplied
    // URL (e.g. a subscriber's personal webhook) must not receive it.
    \Civi::settings()->set('herald_webhook_default_auth_token', 'from-setting');
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('x', ['webhook_url' => 'https://attacker.example/collect']);
    $this->assertSame('', $this->capturedRequests[0]['request']->getHeaderLine('Authorization'));
  }

  public function testEmptyConfigUrlFallsBackToDefault(): void {
    \Civi::settings()->set('herald_webhook_default_url', 'https://example.com/default');
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('x', ['webhook_url' => '']);
    $this->assertSame('https://example.com/default', (string) $this->capturedRequests[0]['request']->getUri());
  }

  public function testExplicitAuthorizationHeaderNotOverwritten(): void {
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('x', [
      'webhook_url' => 'https://example.com/x',
      'webhook_auth_token' => 'shorthand',
      'webhook_headers' => ['Authorization' => 'Basic abc=='],
    ]);
    $this->assertSame('Basic abc==', $this->capturedRequests[0]['request']->getHeaderLine('Authorization'));
  }

  public function testFallsBackToDefaultUrl(): void {
    \Civi::settings()->set('herald_webhook_default_url', 'https://example.com/default');
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('x', []);
    $this->assertSame('https://example.com/default', (string) $this->capturedRequests[0]['request']->getUri());
  }

  public function testThrowsWhenUrlMissing(): void {
    $channel = new WebhookChannel($this->makeMockedClient(new Response(200, [], '')));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/webhook_url/');
    $channel->send('x', []);
  }

  public function testThrowsOnHttpError(): void {
    $channel = new WebhookChannel($this->makeMockedClient(new Response(500, [], 'boom')));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/HTTP 500/');
    $channel->send('x', ['webhook_url' => 'https://example.com/x']);
  }

  public function testBuildPayloadIsPure(): void {
    $this->assertSame(
      ['type' => 'json', 'data' => ['text' => 'hi']],
      WebhookChannel::buildPayload('hi', 'slack')
    );
    $this->assertSame(
      ['type' => 'json', 'data' => ['content' => 'hi']],
      WebhookChannel::buildPayload('hi', 'discord')
    );
    $this->assertSame(
      ['type' => 'json', 'data' => ['msg' => 'hi']],
      WebhookChannel::buildPayload('hi', 'json', 'msg')
    );
    $this->assertSame(['type' => 'raw', 'data' => 'hi'], WebhookChannel::buildPayload('hi', 'text'));
  }

}
