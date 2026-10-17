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
class NtfyChannelTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var \GuzzleHttp\Psr7\Request[] */
  private array $capturedRequests = [];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->capturedRequests = [];
    \Civi::settings()->set('herald_ntfy_server', 'https://ntfy.example.com');
    \Civi::settings()->set('herald_ntfy_default_topic', '');
    \Civi::settings()->set('herald_ntfy_token', '');
  }

  private function makeMockedClient(Response $response): Client {
    $mock = new MockHandler([$response]);
    $stack = HandlerStack::create($mock);
    $stack->push(\GuzzleHttp\Middleware::history($this->capturedRequests));
    return new Client(['handler' => $stack]);
  }

  public function testSendsToTopicWithBody(): void {
    $channel = new NtfyChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('alarm!', ['ntfy_topic' => 'donations']);

    $req = $this->capturedRequests[0]['request'];
    $this->assertSame('POST', $req->getMethod());
    $this->assertSame('https://ntfy.example.com/donations', (string) $req->getUri());
    $this->assertSame('alarm!', (string) $req->getBody());
  }

  public function testBodyIsTheVisibleTextOfTheHtmlBody(): void {
    $channel = new NtfyChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send("<b>Tom &amp; Jerry &lt;x&gt;</b>\nline 2", ['ntfy_topic' => 'donations']);

    $this->assertSame("Tom & Jerry <x>\nline 2", (string) $this->capturedRequests[0]['request']->getBody());
  }

  public function testFallsBackToDefaultTopic(): void {
    \Civi::settings()->set('herald_ntfy_default_topic', 'fallback-topic');
    $channel = new NtfyChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('hi', []);
    $this->assertSame(
      'https://ntfy.example.com/fallback-topic',
      (string) $this->capturedRequests[0]['request']->getUri(),
    );
  }

  public function testFallsBackToPublicNtfyShWhenServerUnset(): void {
    \Civi::settings()->set('herald_ntfy_server', '');
    $channel = new NtfyChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('hi', ['ntfy_topic' => 'x']);
    $this->assertSame('https://ntfy.sh/x', (string) $this->capturedRequests[0]['request']->getUri());
  }

  public function testHeadersForwardMetadata(): void {
    $channel = new NtfyChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('body', [
      'ntfy_topic' => 't',
      'ntfy_title' => 'Donation alert',
      'ntfy_priority' => 4,
      'ntfy_tags' => 'tada,heart',
      'ntfy_click' => 'https://example.com/c',
      'ntfy_markdown' => TRUE,
    ]);
    $req = $this->capturedRequests[0]['request'];
    $this->assertSame('Donation alert', $req->getHeaderLine('X-Title'));
    $this->assertSame('4', $req->getHeaderLine('X-Priority'));
    $this->assertSame('tada,heart', $req->getHeaderLine('X-Tags'));
    $this->assertSame('https://example.com/c', $req->getHeaderLine('X-Click'));
    $this->assertSame('yes', $req->getHeaderLine('X-Markdown'));
  }

  public function testBearerTokenFromSetting(): void {
    \Civi::settings()->set('herald_ntfy_token', 'tk_abc');
    $channel = new NtfyChannel($this->makeMockedClient(new Response(200, [], '')));
    $channel->send('x', ['ntfy_topic' => 't']);
    $this->assertSame('Bearer tk_abc', $this->capturedRequests[0]['request']->getHeaderLine('Authorization'));
  }

  public function testThrowsWhenTopicMissing(): void {
    $channel = new NtfyChannel($this->makeMockedClient(new Response(200, [], '')));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/ntfy_topic/');
    $channel->send('x', []);
  }

  public function testThrowsOnHttpError(): void {
    $channel = new NtfyChannel($this->makeMockedClient(new Response(403, [], 'forbidden')));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/HTTP 403/');
    $channel->send('x', ['ntfy_topic' => 't']);
  }

  public function testBuildHeadersPure(): void {
    $h = NtfyChannel::buildHeaders([
      'ntfy_title' => 'T',
      'ntfy_priority' => 3,
      'ntfy_markdown' => 1,
    ]);
    $this->assertSame('T', $h['X-Title']);
    $this->assertSame('3', $h['X-Priority']);
    $this->assertSame('yes', $h['X-Markdown']);
    $this->assertArrayNotHasKey('X-Tags', $h);
  }

}
