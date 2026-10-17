<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Channel\ChannelInterface;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Failure semantics of fan-out delivery: per-recipient errors are logged and
 * swallowed, but a TOTAL failure (every send failed) must throw — that is
 * what keeps buffered flush rows alive for a retry when the transport is
 * down. Partial failure counts as delivered; retrying would double-send to
 * the recipients that already got the message.
 *
 * @group headless
 */
class FanOutFailureTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  /**
   * @param array<int, bool> $failByCall TRUE = this call throws.
   * @param array<int, array<string, mixed>> $sent
   */
  private function registry(string $key, array $failByCall, array &$sent): ChannelRegistry {
    $registry = new ChannelRegistry();
    $channel = new class() implements ChannelInterface {

      public string $key = '';

      /** @var array<int, bool> */
      public array $failByCall = [];

      /** @var array<int, array<string, mixed>>|null Reference into the test. */
      public ?array $sent = NULL;

      public function send(string $renderedBody, array $config): void {
        $call = count((array) $this->sent);
        $this->sent[] = $config;
        if ($this->failByCall[$call] ?? FALSE) {
          throw new \RuntimeException('transport down');
        }
      }

      public function getKey(): string {
        return $this->key;
      }

      public function getConfigSchema(): array {
        return [];
      }

      public function getSemanticFields(): array {
        return [];
      }

    };
    $channel->key = $key;
    $channel->failByCall = $failByCall;
    $channel->sent = &$sent;
    $registry->register($channel);
    return $registry;
  }

  public function testTotalRecipientFailureThrows(): void {
    $sent = [];
    $registry = $this->registry('telegram', [TRUE, TRUE], $sent);
    $recipients = [
      ['contact_id' => 1, 'channel' => 'telegram', 'config' => ['chat_id' => 'a']],
      ['contact_id' => 2, 'channel' => 'telegram', 'config' => ['chat_id' => 'b']],
    ];
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/all 2 recipient sends failed/');
    FanOut::toRecipients('body', [], $recipients, $registry);
  }

  public function testPartialRecipientFailureDoesNotThrow(): void {
    $sent = [];
    $registry = $this->registry('telegram', [TRUE, FALSE], $sent);
    $recipients = [
      ['contact_id' => 1, 'channel' => 'telegram', 'config' => ['chat_id' => 'a']],
      ['contact_id' => 2, 'channel' => 'telegram', 'config' => ['chat_id' => 'b']],
    ];
    FanOut::toRecipients('body', [], $recipients, $registry);
    $this->assertCount(2, $sent);
  }

  public function testARecipientOnAnUnknownChannelDoesNotStopTheOthers(): void {
    $sent = [];
    $registry = $this->registry('telegram', [], $sent);
    $recipients = [
      ['contact_id' => 1, 'channel' => 'herald_test_gone', 'config' => []],
      ['contact_id' => 2, 'channel' => 'telegram', 'config' => ['chat_id' => 'b']],
    ];
    FanOut::toRecipients('body', [], $recipients, $registry);
    $this->assertSame([['chat_id' => 'b']], $sent);
  }

  /**
   * Nobody was notified, so nothing may be recorded as delivered: a fan-out
   * that resolves to no recipient (deactivated subscription type, emptied
   * group) is a config fault, and a buffered flush must keep its rows for it.
   */
  public function testEmptyRecipientListThrows(): void {
    $sent = [];
    $registry = $this->registry('telegram', [], $sent);
    try {
      FanOut::toRecipients('body', ['delivery_mode' => 'subscribers'], [], $registry);
      $this->fail('an empty recipient list must not count as a successful send');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('no recipients resolved', $e->getMessage());
      $this->assertStringContainsString('subscribers', $e->getMessage());
    }
    $this->assertSame([], $sent);
  }

  /**
   * The end-to-end consequence of the above: an aggregated subscriber alert
   * whose subscription resolves to nobody keeps its buffer rows instead of
   * being deleted as sent.
   */
  public function testBufferFlushRetainsRowsWhenNoSubscriberResolves(): void {
    $buffer = new \Civi\Herald\Buffer\AlertBuffer();
    $buffer->add(
      'grp-no-subscribers',
      1,
      'body',
      ['delivery_mode' => 'subscribers', 'subscription_type' => 'herald_test_unused_type'],
      NULL,
    );
    \CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_herald_buffer SET created_date = DATE_SUB(NOW(), INTERVAL 10 MINUTE)
       WHERE group_hash = 'grp-no-subscribers'",
    );

    $result = civicrm_api3('Job', 'herald_buffer_flush', []);
    $counts = (array) reset($result['values']);

    $this->assertSame(0, (int) $counts['sent']);
    $this->assertSame(1, (int) $counts['failed']);
    $this->assertSame(
      1,
      (int) \CRM_Core_DAO::singleValueQuery(
        "SELECT COUNT(*) FROM civicrm_herald_buffer WHERE group_hash = 'grp-no-subscribers'",
      ),
      'an alert nobody received must stay in the buffer',
    );
  }

  public function testTotalAddressFailureThrows(): void {
    $sent = [];
    $registry = $this->registry('email', [TRUE, TRUE], $sent);
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/all 2 address sends failed/');
    FanOut::toAddresses('body', ['email_addresses' => 'a@x.org, b@x.org'], $registry);
  }

  public function testPartialAddressFailureDoesNotThrow(): void {
    $sent = [];
    $registry = $this->registry('email', [FALSE, TRUE], $sent);
    FanOut::toAddresses('body', ['email_addresses' => 'a@x.org, b@x.org'], $registry);
    $this->assertCount(2, $sent);
  }

  /**
   * The end-to-end consequence: a buffered group whose flush delivery fails
   * completely keeps its rows and reports failed, not sent.
   */
  public function testBufferFlushRetainsRowsOnTotalFailure(): void {
    // No DDL here: the install step (CRM_Herald_Upgrader::install) already
    // created the table, and a CREATE TABLE would implicitly commit.
    $buffer = new \Civi\Herald\Buffer\AlertBuffer();
    // group mode without a group_id makes Delivery::send() throw deterministically —
    // the flush must treat that like any transport failure and keep the rows.
    $buffer->add('grp', 1, 'body', ['delivery_mode' => 'group', 'group_id' => 0], NULL);
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_herald_buffer SET created_date = DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
    );
    $before = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer');
    $this->assertSame(1, $before);

    $result = civicrm_api3('Job', 'herald_buffer_flush', []);
    $counts = (array) reset($result['values']);
    $this->assertSame(1, (int) $counts['failed']);
    $this->assertSame(0, (int) $counts['sent']);
    $this->assertSame(
      1,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer'),
      'buffer rows must survive a failed flush for the next retry',
    );
  }

}
