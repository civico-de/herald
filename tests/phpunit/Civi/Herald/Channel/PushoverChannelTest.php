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
class PushoverChannelTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var \GuzzleHttp\Psr7\Request[] */
  private array $capturedRequests = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->capturedRequests = [];
    \Civi::settings()->set('herald_pushover_app_token', 'apptoken');
    \Civi::settings()->set('herald_pushover_default_user_key', '');
  }

  private function makeMockedClient(Response $response): Client {
    $mock = new MockHandler([$response]);
    $stack = HandlerStack::create($mock);
    $stack->push(\GuzzleHttp\Middleware::history($this->capturedRequests));
    return new Client(['handler' => $stack]);
  }

  private function parseFormBody(string $raw): array {
    parse_str($raw, $parsed);
    return $parsed;
  }

  public function testHappyPathSendsRequiredFormFields(): void {
    $channel = new PushoverChannel($this->makeMockedClient(new Response(200, [], '{"status":1}')));
    $channel->send('hello', ['pushover_user_key' => 'userkey']);

    $req = $this->capturedRequests[0]['request'];
    $this->assertSame('POST', $req->getMethod());
    $this->assertSame('https://api.pushover.net/1/messages.json', (string) $req->getUri());

    $form = $this->parseFormBody((string) $req->getBody());
    $this->assertSame('apptoken', $form['token']);
    $this->assertSame('userkey', $form['user']);
    $this->assertSame('hello', $form['message']);
  }

  public function testFallsBackToDefaultUserKey(): void {
    \Civi::settings()->set('herald_pushover_default_user_key', 'default-user');
    $channel = new PushoverChannel($this->makeMockedClient(new Response(200, [], '{"status":1}')));
    $channel->send('x', []);
    $form = $this->parseFormBody((string) $this->capturedRequests[0]['request']->getBody());
    $this->assertSame('default-user', $form['user']);
  }

  public function testOptionalFieldsForwarded(): void {
    $channel = new PushoverChannel($this->makeMockedClient(new Response(200, [], '{"status":1}')));
    $channel->send('body', [
      'pushover_user_key' => 'u',
      'pushover_title' => 'Donation',
      'pushover_priority' => 1,
      'pushover_sound' => 'cashregister',
      'pushover_url' => 'https://example.com',
      'pushover_url_title' => 'Open',
      'pushover_html' => TRUE,
    ]);
    $form = $this->parseFormBody((string) $this->capturedRequests[0]['request']->getBody());
    $this->assertSame('Donation', $form['title']);
    $this->assertSame('1', $form['priority']);
    $this->assertSame('cashregister', $form['sound']);
    $this->assertSame('https://example.com', $form['url']);
    $this->assertSame('Open', $form['url_title']);
    $this->assertSame('1', $form['html']);
  }

  public function testThrowsWhenAppTokenMissing(): void {
    \Civi::settings()->set('herald_pushover_app_token', '');
    $channel = new PushoverChannel($this->makeMockedClient(new Response(200, [], '{"status":1}')));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/app token/');
    $channel->send('x', ['pushover_user_key' => 'u']);
  }

  public function testThrowsWhenUserKeyMissing(): void {
    $channel = new PushoverChannel($this->makeMockedClient(new Response(200, [], '{"status":1}')));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/user.*key/i');
    $channel->send('x', []);
  }

  public function testThrowsOnHttpError(): void {
    $channel = new PushoverChannel($this->makeMockedClient(new Response(429, [], '{"status":0}')));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/HTTP 429/');
    $channel->send('x', ['pushover_user_key' => 'u']);
  }

  public function testThrowsWhenStatusNotOne(): void {
    $channel = new PushoverChannel($this->makeMockedClient(new Response(200, [], '{"status":0,"errors":["bad token"]}')));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/status != 1/');
    $channel->send('x', ['pushover_user_key' => 'u']);
  }

  public function testBuildFormPure(): void {
    $form = PushoverChannel::buildForm('app', 'u', 'msg', ['pushover_priority' => -1, 'pushover_html' => TRUE]);
    $this->assertSame('app', $form['token']);
    $this->assertSame('u', $form['user']);
    $this->assertSame('msg', $form['message']);
    $this->assertSame(-1, $form['priority']);
    $this->assertSame(1, $form['html']);
    $this->assertArrayNotHasKey('title', $form);
  }

  public function testEmergencyPriorityAddsRetryAndExpire(): void {
    // Priority 2 without retry/expire is rejected by the Pushover API.
    $form = PushoverChannel::buildForm('app', 'u', 'msg', ['pushover_priority' => 2]);
    $this->assertSame(2, $form['priority']);
    $this->assertSame(60, $form['retry']);
    $this->assertSame(3600, $form['expire']);
  }

  public function testEmergencyRetryAndExpireClampedToApiLimits(): void {
    $form = PushoverChannel::buildForm('app', 'u', 'msg', [
      'pushover_priority' => 2,
      'pushover_retry' => 5,
      'pushover_expire' => 99999,
    ]);
    $this->assertSame(30, $form['retry']);
    $this->assertSame(10800, $form['expire']);
  }

  public function testNonEmergencyPriorityHasNoRetryExpire(): void {
    $form = PushoverChannel::buildForm('app', 'u', 'msg', ['pushover_priority' => 1]);
    $this->assertArrayNotHasKey('retry', $form);
    $this->assertArrayNotHasKey('expire', $form);
  }

}
