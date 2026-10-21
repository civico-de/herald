<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Channel\ChannelInterface;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ChannelStore;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A subscriber's message goes to the subscriber alone: the recipient
 * defaults stored on the channel apply to direct sends only.
 *
 * @group headless
 */
class SubscriberDefaultsTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testStoredRecipientKeysStayOutOfASubscriberSend(): void {
    $sent = [];
    $sender = $this->sender($sent);

    FanOut::toRecipients(
      'body',
      [],
      [
        ['contact_id' => 1, 'channel' => 'chan', 'config' => ['to' => 'a@example.org']],
        ['contact_id' => 2, 'channel' => 'chan', 'config' => ['to' => 'b@example.org']],
      ],
      $sender,
    );
    $sender->send('chan', 'body', []);

    $this->assertSame(
      [
        ['to' => 'a@example.org', 'tone' => 'calm'],
        ['to' => 'b@example.org', 'tone' => 'calm'],
        ['cc' => 'archive@example.org', 'to' => 'team@example.org', 'tone' => 'calm'],
      ],
      $sent,
    );
  }

  public function testStoredRecipientKeysStayOutOfAnAddressListSend(): void {
    $sent = [];
    $sender = $this->sender($sent, 'email');

    FanOut::toAddresses('body', ['channel' => 'chan', 'email_addresses' => 'a@example.org'], $sender);

    $this->assertSame([['email_to' => 'a@example.org', 'tone' => 'calm']], $sent);
  }

  /**
   * @param list<array<string, mixed>> $sent
   */
  private function sender(array &$sent, string $type = 'stub'): ChannelSender {
    $channel = new class() implements ChannelInterface {

      /** @var list<array<string, mixed>>|null Reference into the test. */
      public ?array $sent = NULL;

      public function send(string $renderedBody, array $config): void {
        ksort($config);
        $this->sent[] = $config;
      }

      public string $key = '';

      public function getKey(): string {
        return $this->key;
      }

      public function getConfigSchema(): array {
        return [
          'to' => ['label' => 'To', 'required' => FALSE, 'type' => 'string', 'recipient' => TRUE],
          'email_to' => ['label' => 'Email', 'required' => FALSE, 'type' => 'string', 'recipient' => TRUE],
          'cc' => ['label' => 'Cc', 'required' => FALSE, 'type' => 'string', 'recipient' => TRUE],
          'tone' => ['label' => 'Tone', 'required' => FALSE, 'type' => 'string'],
        ];
      }

      public function getSemanticFields(): array {
        return [];
      }

    };
    $channel->sent = &$sent;
    $channel->key = $type;
    $registry = new ChannelRegistry();
    $registry->register($channel);
    $store = new ChannelStore($registry);
    $this->assertSame(
      [],
      $store->create(
        'chan',
        [
          'type' => $type,
          'label' => 'Stub',
          'config' => ['to' => 'team@example.org', 'cc' => 'archive@example.org', 'tone' => 'calm'],
        ],
      ),
    );
    return new ChannelSender($registry, $store);
  }

}
