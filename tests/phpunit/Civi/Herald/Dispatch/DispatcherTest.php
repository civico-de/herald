<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the dispatcher's channel-config assembly.
 * @group unit
 */
class DispatcherTest extends TestCase {

  public function testStripsRoutingKeys(): void {
    $config = Dispatcher::buildChannelConfig([
      'channel' => 'telegram',
      'template' => 'foo',
      'chat_id' => '-100',
    ]);
    $this->assertArrayNotHasKey('channel', $config);
    $this->assertArrayNotHasKey('template', $config);
    $this->assertSame('-100', $config['chat_id']);
  }

  public function testChannelConfigJsonMergedAndStripped(): void {
    $config = Dispatcher::buildChannelConfig([
      'channel' => 'webhook',
      'template' => 'foo',
      'channel_config' => '{"webhook_url": "https://example.com/x", "webhook_body_format": "slack"}',
    ]);
    $this->assertArrayNotHasKey('channel_config', $config);
    $this->assertSame('https://example.com/x', $config['webhook_url']);
    $this->assertSame('slack', $config['webhook_body_format']);
  }

  public function testChannelConfigArrayPassthrough(): void {
    $config = Dispatcher::buildChannelConfig([
      'channel' => 'ntfy',
      'template' => 'foo',
      'channel_config' => ['ntfy_topic' => 'donations', 'ntfy_priority' => 4],
    ]);
    $this->assertSame('donations', $config['ntfy_topic']);
    $this->assertSame(4, $config['ntfy_priority']);
  }

  public function testChannelConfigOverridesTopLevel(): void {
    // channel_config takes precedence — explicit per-channel overrides win.
    $config = Dispatcher::buildChannelConfig([
      'channel' => 'telegram',
      'template' => 'foo',
      'chat_id' => '-100',
      'channel_config' => ['chat_id' => '-200'],
    ]);
    $this->assertSame('-200', $config['chat_id']);
  }

  public function testFilterSubscriberSafeStripsRecipientAndCredentialKeys(): void {
    $filtered = Dispatcher::filterSubscriberSafe([
      'chat_id' => '-100',
      'webhook_url' => 'https://example.com/rule-endpoint',
      // @mago-expect lint:no-literal-password
      // Test fixture, not a real credential.
      'webhook_auth_token' => 'secret',
      'webhook_headers' => ['Authorization' => 'Basic abc=='],
      'ntfy_topic' => 'rule-topic',
      'pushover_user_key' => 'rule-key',
      'email_to' => 'rule-inbox@example.org',
      'email_cc' => 'cc@example.org',
      'email_bcc' => 'bcc@example.org',
      'delivery_mode' => 'subscribers',
      'subscription_type' => 'completed_alert',
      'parse_mode' => 'HTML',
      'disable_notification' => TRUE,
      'ntfy_title' => 'Donation',
      'pushover_sound' => 'cashregister',
      'webhook_body_format' => 'slack',
      'email_subject' => 'Donation received',
    ]);
    $this->assertSame(
      [
        'parse_mode' => 'HTML',
        'disable_notification' => TRUE,
        'ntfy_title' => 'Donation',
        'pushover_sound' => 'cashregister',
        'webhook_body_format' => 'slack',
        'email_subject' => 'Donation received',
      ],
      $filtered,
    );
  }

  public function testTemplateSubjectBecomesEmailSubjectDefault(): void {
    $params = Dispatcher::applyDefaultEmailSubject(
      ['channel' => 'email'],
      '✅ 42,50 € — Stripe',
    );
    $this->assertSame('✅ 42,50 € — Stripe', $params['email_subject']);
  }

  public function testExplicitEmailSubjectWinsOverTemplateSubject(): void {
    $params = Dispatcher::applyDefaultEmailSubject(
      ['email_subject' => 'Manual subject'],
      'Template subject',
    );
    $this->assertSame('Manual subject', $params['email_subject']);
  }

  public function testNoTemplateSubjectLeavesParamsUntouched(): void {
    foreach ([NULL, ''] as $subject) {
      $params = Dispatcher::applyDefaultEmailSubject(['channel' => 'email'], $subject);
      $this->assertArrayNotHasKey('email_subject', $params);
    }
  }

  /**
   * Why the group key must be taken before the rendered subject is applied:
   * with the subject in the params every message forms its own group and the
   * aggregation window only ever adds latency.
   */
  public function testGroupHashChangesOnceARenderedSubjectIsMixedIn(): void {
    $raw = ['channel' => 'email', 'template' => 'alert', 'aggregate_minutes' => 15];
    $this->assertNotSame(
      Dispatcher::groupHash($raw),
      Dispatcher::groupHash(Dispatcher::applyDefaultEmailSubject($raw, '✅ Donation #5')),
    );
  }

  public function testGroupHashIsStableAndNamespacedByPrefix(): void {
    $params = ['channel' => 'telegram', 'chat_id' => '-100'];
    $this->assertSame(Dispatcher::groupHash($params), Dispatcher::groupHash($params));
    $this->assertNotSame(Dispatcher::groupHash($params), Dispatcher::groupHash($params, 'topic:ops:'));
  }

  /**
   * An unencodable params bag must fail loudly: an empty replacement string
   * collapses every affected configuration onto one hash and mixes unrelated
   * alerts into a single message.
   */
  public function testGroupHashRefusesUnencodableParams(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/aggregation group key/');
    Dispatcher::groupHash(['chat_id' => '-100', 'retry_after' => INF]);
  }

  public function testParseAddressListSplitsAndDeduplicates(): void {
    $this->assertSame(
      ['ops@example.org', 'oncall@example.org', 'third@example.org'],
      Dispatcher::parseAddressList("ops@example.org, oncall@example.org;\nops@example.org\nthird@example.org"),
    );
  }

  public function testParseAddressListHandlesEmptyAndNonScalar(): void {
    $this->assertSame([], Dispatcher::parseAddressList(''));
    $this->assertSame([], Dispatcher::parseAddressList(NULL));
    $this->assertSame(['a@example.org'], Dispatcher::parseAddressList(['a@example.org', '']));
  }

  public function testDeliveryOptionsDefaultsKeepCurrentBehavior(): void {
    $this->assertSame(
      ['respect_suspension' => FALSE, 'location_type_id' => 0],
      Dispatcher::deliveryOptions([]),
    );
  }

  public function testDeliveryOptionsPicksUpSuspensionLocationAndScope(): void {
    $options = Dispatcher::deliveryOptions([
      'respect_suspension' => TRUE,
      'email_location_type' => '3',
      'scope_entity_type' => 'Event',
      'scope_entity_id' => 5,
    ]);
    $this->assertTrue($options['respect_suspension']);
    $this->assertSame(3, $options['location_type_id']);
    $this->assertSame(['entity_type' => 'Event', 'entity_id' => 5], $options['scope']);
  }

  public function testDeliveryOptionsIgnoresIncompleteScope(): void {
    $this->assertArrayNotHasKey(
      'scope',
      Dispatcher::deliveryOptions(['scope_entity_type' => 'Event']),
    );
  }

  public function testGroupModeWithoutGroupIdThrows(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/group_id/');
    Dispatcher::deliver('body', ['delivery_mode' => 'group']);
  }

  public function testInvalidChannelConfigJsonIsIgnored(): void {
    $config = Dispatcher::buildChannelConfig([
      'channel' => 'telegram',
      'template' => 'foo',
      'chat_id' => '-100',
      'channel_config' => 'not-json',
    ]);
    $this->assertSame('-100', $config['chat_id']);
    $this->assertArrayNotHasKey('channel_config', $config);
  }

}
