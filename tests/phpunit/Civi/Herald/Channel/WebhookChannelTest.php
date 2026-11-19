<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class WebhookChannelTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var \GuzzleHttp\Psr7\Request[] */
  private array $capturedRequests = [];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->capturedRequests = [];
  }

  private function makeMockedClient(Response $response): Client {
    $mock = new MockHandler([$response]);
    $stack = HandlerStack::create($mock);
    $stack->push(\GuzzleHttp\Middleware::history($this->capturedRequests));
    return new Client(['handler' => $stack]);
  }

  /**
   * Channel wired to the mock client and a stub resolver that maps every host
   * to a fixed public IP, so send() tests exercise the SSRF guard offline.
   */
  private function makeChannel(Response $response): WebhookChannel {
    return new WebhookChannel($this->makeMockedClient($response), static fn (string $host): array => ['93.184.216.34']);
  }

  public function testTextBodySentRaw(): void {
    $channel = $this->makeChannel(new Response(200, [], ''));
    $channel->send('hello world', ['webhook_url' => 'https://example.com/hook']);

    $req = $this->capturedRequests[0]['request'];
    $this->assertSame('POST', $req->getMethod());
    $this->assertSame('https://example.com/hook', (string) $req->getUri());
    $this->assertSame('hello world', (string) $req->getBody());
    $this->assertStringContainsString('text/plain', $req->getHeaderLine('Content-Type'));
  }

  public function testSlackFormatWrapsAsJson(): void {
    $channel = $this->makeChannel(new Response(200, [], ''));
    $channel->send('hi', [
      'webhook_url' => 'https://hooks.slack.com/services/X',
      'webhook_body_format' => 'slack',
    ]);
    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertSame(['text' => 'hi'], $body);
  }

  public function testDiscordFormatUsesContentKey(): void {
    $channel = $this->makeChannel(new Response(200, [], ''));
    $channel->send('hi', [
      'webhook_url' => 'https://discord.com/api/webhooks/X',
      'webhook_body_format' => 'discord',
    ]);
    $body = json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE);
    $this->assertSame(['content' => 'hi'], $body);
  }

  public function testCustomJsonKey(): void {
    $channel = $this->makeChannel(new Response(200, [], ''));
    $channel->send('hi', [
      'webhook_url' => 'https://example.com/x',
      'webhook_body_format' => 'json',
      'webhook_body_key' => 'message',
    ]);
    $this->assertSame(
      ['message' => 'hi'],
      json_decode((string) $this->capturedRequests[0]['request']->getBody(), TRUE),
    );
  }

  public function testBearerTokenFromConfig(): void {
    $token = bin2hex(random_bytes(8));
    $channel = $this->makeChannel(new Response(200, [], ''));
    $channel->send('x', [
      'webhook_url' => 'https://example.com/x',
      'webhook_auth_token' => $token,
    ]);
    $this->assertSame("Bearer {$token}", $this->capturedRequests[0]['request']->getHeaderLine('Authorization'));
  }

  /**
   * Sends through a configured channel "hook" holding a default URL and token.
   *
   * @return string the channel's token
   */
  private function sendThroughChannel(array $delivery): string {
    $token = bin2hex(random_bytes(8));
    \Civi\Herald\ChannelFixture::add('hook', 'webhook', [
      'webhook_url' => 'https://example.com/default',
      'webhook_auth_token' => $token,
    ]);
    $registry = new ChannelRegistry();
    $registry->register($this->makeChannel(new Response(200, [], '')));
    (new \Civi\Herald\Dispatch\ChannelSender($registry))->send('hook', 'x', $delivery);
    return $token;
  }

  public function testTheChannelsTokenPairsWithItsUrl(): void {
    $token = $this->sendThroughChannel(['webhook_url' => '']);
    $request = $this->capturedRequests[0]['request'];
    $this->assertSame('https://example.com/default', (string) $request->getUri());
    $this->assertSame("Bearer {$token}", $request->getHeaderLine('Authorization'));
  }

  public function testTheChannelsTokenIsNotSentToADeliveryUrl(): void {
    // A caller-supplied URL (e.g. a subscriber's personal webhook) must not receive it.
    $this->sendThroughChannel(['webhook_url' => 'https://attacker.example/collect']);
    $request = $this->capturedRequests[0]['request'];
    $this->assertSame('https://attacker.example/collect', (string) $request->getUri());
    $this->assertSame('', $request->getHeaderLine('Authorization'));
  }

  public function testExplicitAuthorizationHeaderNotOverwritten(): void {
    $channel = $this->makeChannel(new Response(200, [], ''));
    $channel->send('x', [
      'webhook_url' => 'https://example.com/x',
      'webhook_auth_token' => bin2hex(random_bytes(8)),
      'webhook_headers' => ['Authorization' => 'Basic abc=='],
    ]);
    $this->assertSame('Basic abc==', $this->capturedRequests[0]['request']->getHeaderLine('Authorization'));
  }

  public function testThrowsWhenUrlMissing(): void {
    $channel = $this->makeChannel(new Response(200, [], ''));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/webhook_url/');
    $channel->send('x', []);
  }

  public function testThrowsOnHttpError(): void {
    $channel = $this->makeChannel(new Response(500, [], 'boom'));
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/HTTP 500/');
    $channel->send('x', ['webhook_url' => 'https://example.com/x']);
  }

  public function testBuildPayloadIsPure(): void {
    $this->assertSame(
      ['type' => 'json', 'data' => ['text' => 'hi']],
      WebhookChannel::buildPayload('hi', 'slack'),
    );
    $this->assertSame(
      ['type' => 'json', 'data' => ['content' => 'hi']],
      WebhookChannel::buildPayload('hi', 'discord'),
    );
    $this->assertSame(
      ['type' => 'json', 'data' => ['msg' => 'hi']],
      WebhookChannel::buildPayload('hi', 'json', 'msg'),
    );
    $this->assertSame(['type' => 'raw', 'data' => 'hi'], WebhookChannel::buildPayload('hi', 'text'));
  }

  /**
   * @return array<string, array{0: string}> */
  public static function blockedUrlProvider(): array {
    return [
      'loopback ipv4' => ['http://127.0.0.1/x'],
      'cloud metadata' => ['http://169.254.169.254/latest/meta-data'],
      'private 10/8' => ['http://10.0.0.5/x'],
      'private 192.168' => ['http://192.168.1.1/x'],
      'link-local' => ['http://169.254.10.10/x'],
      'ipv6 loopback' => ['http://[::1]/x'],
      'zero address' => ['http://0.0.0.0/x'],
      'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/x'],
      'ipv4-mapped metadata' => ['http://[::ffff:169.254.169.254]/x'],
      'ipv6 unique local' => ['http://[fd12:3456::1]/x'],
      'ipv6 link-local' => ['http://[fe80::1]/x'],
      'ipv6 link-local upper end' => ['http://[febf::1]/x'],
    ];
  }

  /**
   * @dataProvider blockedUrlProvider */
  public function testGuardRejectsLiteralPrivateAndReservedIps(string $url): void {
    $this->expectException(\CRM_Core_Exception::class);
    WebhookUrlGuard::assert($url);
  }

  public function testGuardRejectsNonHttpScheme(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/http or https/');
    WebhookUrlGuard::assert('gopher://example.com/x');
  }

  public function testGuardRejectsFileScheme(): void {
    $this->expectException(\CRM_Core_Exception::class);
    WebhookUrlGuard::assert('file:///etc/passwd');
  }

  public function testGuardRejectsCredentialsInUrl(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/credentials/');
    WebhookUrlGuard::assert('https://user:pass@example.com/x');
  }

  public function testGuardRejectsDnsNameResolvingToPrivateIp(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/disallowed address/');
    WebhookUrlGuard::assert('https://sneaky.example/x', [
      'resolver' => static fn (string $host): array => ['10.1.2.3'],
    ]);
  }

  public function testGuardAcceptsPublicHost(): void {
    WebhookUrlGuard::assert('https://hooks.slack.com/services/X', [
      'resolver' => static fn (string $host): array => ['93.184.216.34'],
    ]);
    $this->addToAssertionCount(1);
  }

  public function testResolverFailureStopsDeliveryBeforeHttpRequest(): void {
    $channel = new WebhookChannel(
      $this->makeMockedClient(new Response(200, [], '')),
      static function (string $host): array {
        throw new \RuntimeException('Resolver unavailable.');
      },
    );

    try {
      $channel->send('must not be delivered', ['webhook_url' => 'https://example.com/x']);
      $this->fail('Expected the DNS lookup failure to stop webhook delivery.');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('DNS lookup failed for webhook host example.com', $e->getMessage());
    }

    $this->assertSame([], $this->capturedRequests);
  }

  public function testConnectionIsPinnedToTheValidatedAddress(): void {
    // The guard validates the resolved address; the request must then connect to
    // exactly that address, so a second DNS lookup at connect time cannot swing
    // it to an internal host (rebinding).
    $channel = new WebhookChannel(
      $this->makeMockedClient(new Response(200, [], '')),
      static fn (string $host): array => ['93.184.216.34'],
    );
    $channel->send('x', ['webhook_url' => 'https://example.com/hook']);

    $options = $this->capturedRequests[0]['options'];
    $this->assertSame(['example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE] ?? NULL);
  }

  public function testAllowlistedHostIsNotPinned(): void {
    // In allowlist mode the admin vouches for the host (it may be internal), so
    // the connection resolves normally and nothing is pinned.
    \Civi::settings()->set('herald_webhook_allowed_hosts', ['example.com']);
    $this->makeChannel(new Response(200, [], ''))->send('x', ['webhook_url' => 'https://example.com/hook']);

    $this->assertArrayNotHasKey('curl', $this->capturedRequests[0]['options']);
  }

  public function testARebindingHostIsPinnedToTheAnswerTheGuardChecked(): void {
    // First answer public, every later one internal: the request must carry the checked answer.
    $calls = 0;
    $channel = new WebhookChannel(
      $this->makeMockedClient(new Response(200, [], '')),
      static function (string $host) use (&$calls): array {
        return ++$calls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
      },
    );
    $channel->send('x', ['webhook_url' => 'https://rebind.example:8443/hook']);

    $options = $this->capturedRequests[0]['options'];
    $this->assertSame(['rebind.example:8443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE] ?? NULL);
    $this->assertSame(1, $calls);
  }

  public function testARedirectToAnInternalAddressIsNotFollowed(): void {
    $mock = new MockHandler([
      new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
      new Response(200, [], ''),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(\GuzzleHttp\Middleware::history($this->capturedRequests));
    $channel = new WebhookChannel(new Client(['handler' => $stack]), static fn (string $host): array => [
      '93.184.216.34',
    ]);

    try {
      $channel->send('x', ['webhook_url' => 'https://example.com/hook']);
      $this->fail('Expected the redirect to fail the send.');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('webhook returned HTTP 302', $e->getMessage());
    }
    $this->assertSame(['https://example.com/hook'], array_map(
      static fn (array $entry): string => (string) $entry['request']->getUri(),
      $this->capturedRequests,
    ));
  }

  public function testSendConnectsToTheCheckedAddressNotTheConnectTimeAnswer(): void {
    // System DNS sends localhost to 127.0.0.1 (the rebinding target); the checked address is 127.0.0.2.
    [$target, $port] = $this->listen('127.0.0.1');
    [$pinned] = $this->listen('127.0.0.2', $port);

    $this->assertSame('127.0.0.2', $this->connectedAddressOfSend("http://localhost:{$port}/hook"));
    fclose($target);
    fclose($pinned);
  }

  public function testSendBypassesAnEnvironmentProxy(): void {
    [$pinned, $port] = $this->listen('127.0.0.2');
    [$proxy, $proxyPort] = $this->listen('127.0.0.3');
    putenv("http_proxy=http://127.0.0.3:{$proxyPort}");
    putenv("HTTP_PROXY=http://127.0.0.3:{$proxyPort}");
    try {
      $this->assertSame('127.0.0.2', $this->connectedAddressOfSend("http://localhost:{$port}/hook"));
      $read = [$proxy];
      $write = NULL;
      $except = NULL;
      $this->assertSame(0, stream_select($read, $write, $except, 0), 'The proxy received a connection.');
    }
    finally {
      putenv('http_proxy');
      putenv('HTTP_PROXY');
      fclose($pinned);
      fclose($proxy);
    }
  }

  public function testAnIpLiteralUrlConnectsWithoutAResolveEntry(): void {
    $this->assertSame(['proxy' => ''], WebhookPin::options('http://[2001:4860:4860::8888]/', ['2001:4860:4860::8888']));

    $server = @stream_socket_server('tcp://[::1]:0'); // @mago-expect lint:no-error-control-operator the host may lack IPv6; that skips the test.
    if ($server === FALSE) {
      $this->markTestSkipped('No IPv6 loopback.');
    }
    $url = 'http://[::1]:' . parse_url('tcp://' . stream_socket_get_name($server, FALSE), PHP_URL_PORT) . '/';
    $ip = '';
    (new Client())
      ->requestAsync(
        'GET',
        $url,
        WebhookPin::options($url, ['::1'])
        + [
          'timeout' => 0.5,
          'on_stats' => static function (\GuzzleHttp\TransferStats $stats) use (&$ip): void {
            $ip = (string) ($stats->getHandlerStats()['primary_ip'] ?? '');
          },
        ],
      )
      ->wait(FALSE);
    $this->assertSame('::1', $ip);
    fclose($server);
  }

  /**
   * @return array{resource, int}
   */
  private function listen(string $ip, int $port = 0): array {
    $server = stream_socket_server("tcp://{$ip}:{$port}");
    $this->assertNotFalse($server);
    return [$server, (int) parse_url('tcp://' . stream_socket_get_name($server, FALSE), PHP_URL_PORT)];
  }

  /**
   * Sends through real curl, loopback only: the checked public address is swapped for
   * 127.0.0.2 so nothing leaves the host. Returns the address curl connected to.
   */
  private function connectedAddressOfSend(string $url): string {
    $ip = '';
    $stack = HandlerStack::create();
    $stack->push(static function (callable $handler) use (&$ip): callable {
      return static function ($request, array $options) use ($handler, &$ip) {
        if (isset($options['curl'][CURLOPT_RESOLVE])) {
          $options['curl'][CURLOPT_RESOLVE] = str_replace(
            '93.184.216.34',
            '127.0.0.2',
            $options['curl'][CURLOPT_RESOLVE],
          );
        }
        $options['timeout'] = 0.5;
        $options['on_stats'] = static function (\GuzzleHttp\TransferStats $stats) use (&$ip): void {
          $ip = (string) ($stats->getHandlerStats()['primary_ip'] ?? '');
        };
        return $handler($request, $options);
      };
    });
    $channel = new WebhookChannel(new Client(['handler' => $stack]), static fn (string $host): array => [
      '93.184.216.34',
    ]);

    try {
      $channel->send('x', ['webhook_url' => $url]);
      $this->fail('The loopback listener never answers, so the send must time out.');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('webhook request failed', $e->getMessage());
    }
    return $ip;
  }

  public function testGuardRejectsHostWithNoDnsAddresses(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/could not resolve webhook host/');
    WebhookUrlGuard::assert('https://missing.example/x', [
      'resolver' => static fn (string $host): array => [],
    ]);
  }

  public function testGuardAllowlistPermitsMatchingHostAndSubdomain(): void {
    WebhookUrlGuard::assert('https://hooks.slack.com/x', ['allowed_hosts' => ['slack.com']]);
    $this->addToAssertionCount(1);
  }

  public function testGuardAllowlistRejectsUnlistedHost(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/allowed_hosts/');
    WebhookUrlGuard::assert('https://evil.example/x', ['allowed_hosts' => ['slack.com']]);
  }

  public function testHeadersFromJsonAndAuthorizationMatchedCaseInsensitively(): void {
    $channel = $this->makeChannel(new Response(200, [], ''));
    $channel->send('x', [
      'webhook_url' => 'https://example.com/x',
      'webhook_auth_token' => bin2hex(random_bytes(8)),
      'webhook_headers' => '{"X-Trace": "1", "authorization": "Basic abc=="}',
    ]);
    $request = $this->capturedRequests[0]['request'];
    $this->assertSame('1', $request->getHeaderLine('X-Trace'));
    $this->assertSame('Basic abc==', $request->getHeaderLine('Authorization'));
  }

  /**
   * @return array<string, array{mixed}>
   */
  public static function unusableHeadersProvider(): array {
    return [
      'invalid json' => ['not json'],
      'scalar' => [42],
      'unnamed entries' => [[0 => 'positional', 'X-Ok' => 7]],
      'blank name' => [['' => 'unnamed']],
      'list value' => [['Authorization' => ['Bearer intended']]],
      'null value' => ['{"X-Trace": null}'],
      'false value' => ['{"Authorization": false}'],
      'true value' => ['{"X-Trace": true}'],
    ];
  }

  /**
   * A header that cannot be sent would drop silently, and with it maybe the endpoint's auth.
   *
   * @dataProvider unusableHeadersProvider
   */
  public function testUnusableHeadersFailTheSend(mixed $headers): void {
    $this->expectExceptionMessage('herald: webhook_headers must map header names to text or numbers.');
    $this->makeChannel(new Response(200, [], ''))->send('x', [
      'webhook_url' => 'https://example.com/x',
      'webhook_headers' => $headers,
    ]);
  }

  public function testAnEmptyHeadersFieldSendsNoExtraHeaders(): void {
    $this->makeChannel(new Response(200, [], ''))->send('x', [
      'webhook_url' => 'https://example.com/x',
      'webhook_headers' => ' ',
    ]);
    $sent = $this->capturedRequests[0]['request']->getHeaders();
    $this->assertSame([], array_diff_key($sent, array_flip(['Host', 'User-Agent', 'Content-Type', 'Content-Length'])));
  }

  public function testTransportFailureBecomesACoreException(): void {
    $mock = new MockHandler([new ConnectException('connection refused', new Request('POST', 'https://example.com/x'))]);
    $channel = new WebhookChannel(
      new Client(['handler' => HandlerStack::create($mock)]),
      static fn (string $host): array => ['93.184.216.34'],
    );
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('herald: webhook request failed: connection refused');
    $channel->send('x', ['webhook_url' => 'https://example.com/x']);
  }

  public function testGuardAllowlistSkipsBlankEntries(): void {
    $allowed = ['allowed_hosts' => ['  ', 'slack.com']];
    WebhookUrlGuard::assert('https://hooks.slack.com/x', $allowed);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('not in herald_webhook_allowed_hosts');
    WebhookUrlGuard::assert('https://evil.example/x', $allowed);
  }

  public function testGuardRejectsAResolverAnswerThatIsNoAddress(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('disallowed address');
    WebhookUrlGuard::assert('https://odd.example/x', [
      'resolver' => static fn (string $host): array => ['not-an-ip'],
    ]);
  }

  public function testGuardAcceptsAPublicIpv6Address(): void {
    WebhookUrlGuard::assert('https://v6.example/x', [
      'resolver' => static fn (string $host): array => ['2606:4700:4700::1111'],
    ]);
    $this->addToAssertionCount(1);
  }

  public function testDnsResolverCollectsBothAddressFamiliesOnce(): void {
    $asked = [];
    $ips = WebhookHost::dns('hooks.example', static function (string $host, int $type) use (&$asked): array {
      $asked = [$host, $type];
      return [
        ['type' => 'A', 'ip' => '93.184.216.34'],
        ['type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111'],
        ['type' => 'A', 'ip' => '93.184.216.34'],
        ['type' => 'A', 'ip' => ''],
      ];
    });

    $this->assertSame(['hooks.example', DNS_A | DNS_AAAA], $asked);
    $this->assertSame(['93.184.216.34', '2606:4700:4700::1111'], $ips);
  }

  public function testDnsResolverFailsWhenTheLookupReturnsFalse(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('DNS lookup returned false.');
    WebhookHost::dns('hooks.example', static fn (string $host, int $type): bool => FALSE);
  }

  public function testDnsResolverTurnsALookupWarningIntoAnExceptionAndRestoresTheHandler(): void {
    $outer = static fn (): bool => TRUE;
    set_error_handler($outer);
    try {
      // dns_get_record() reports resolver failures as E_WARNING; hex2bin() raises one offline.
      WebhookHost::dns('hooks.example', static fn (string $host, int $type): array => [hex2bin(
        '0',
      )]);
      $this->fail('a lookup warning must stop resolution');
    }
    catch (\ErrorException $e) {
      $this->assertSame(E_WARNING, $e->getSeverity());
    }
    finally {
      $current = set_error_handler(static fn (): bool => TRUE);
      restore_error_handler();
      restore_error_handler();
    }
    $this->assertSame($outer, $current);
  }

}
