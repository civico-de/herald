<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * How caller params become a channel config.
 * @group unit
 */
class ChannelConfigTest extends TestCase {

  public function testStripsRoutingKeys(): void {
    $config = ChannelConfig::of([
      'channel' => 'telegram',
      'template' => 'foo',
      'chat_id' => '-100',
    ]);
    $this->assertArrayNotHasKey('channel', $config);
    $this->assertArrayNotHasKey('template', $config);
    $this->assertSame('-100', $config['chat_id']);
  }

  public function testChannelConfigJsonMergedAndStripped(): void {
    $config = ChannelConfig::of([
      'channel' => 'webhook',
      'template' => 'foo',
      'channel_config' => '{"webhook_url": "https://example.com/x", "webhook_body_format": "slack"}',
    ]);
    $this->assertArrayNotHasKey('channel_config', $config);
    $this->assertSame('https://example.com/x', $config['webhook_url']);
    $this->assertSame('slack', $config['webhook_body_format']);
  }

  public function testChannelConfigArrayPassthrough(): void {
    $config = ChannelConfig::of([
      'channel' => 'ntfy',
      'template' => 'foo',
      'channel_config' => ['ntfy_topic' => 'donations', 'ntfy_priority' => 4],
    ]);
    $this->assertSame('donations', $config['ntfy_topic']);
    $this->assertSame(4, $config['ntfy_priority']);
  }

  public function testChannelConfigOverridesTopLevel(): void {
    // channel_config takes precedence — explicit per-channel overrides win.
    $config = ChannelConfig::of([
      'channel' => 'telegram',
      'template' => 'foo',
      'chat_id' => '-100',
      'channel_config' => ['chat_id' => '-200'],
    ]);
    $this->assertSame('-200', $config['chat_id']);
  }

  public function testFilterSubscriberSafeStripsRecipientAndCredentialKeys(): void {
    $filtered = ChannelConfig::subscriberSafe([
      'chat_id' => '-100',
      'webhook_url' => 'https://example.com/rule-endpoint',
      'webhook_auth_token' => bin2hex(random_bytes(8)),
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
    $params = ChannelConfig::withSubject(
      ['channel' => 'email'],
      '✅ 42,50 € — Stripe',
    );
    $this->assertSame('✅ 42,50 € — Stripe', $params['email_subject']);
  }

  public function testExplicitEmailSubjectWinsOverTemplateSubject(): void {
    $params = ChannelConfig::withSubject(
      ['email_subject' => 'Manual subject'],
      'Template subject',
    );
    $this->assertSame('Manual subject', $params['email_subject']);
  }

  public function testNoTemplateSubjectLeavesParamsUntouched(): void {
    foreach ([NULL, ''] as $subject) {
      $params = ChannelConfig::withSubject(['channel' => 'email'], $subject);
      $this->assertArrayNotHasKey('email_subject', $params);
    }
  }

  /**
   * Ignoring a broken override would send to the top-level recipient instead
   * of the one the override names.
   */
  public function testChannelConfigThatIsNoJsonObjectFails(): void {
    foreach (['not-json', '"a string"', '42', 42, '[{"chat_id": "-200"}]', [['chat_id' => '-200']]] as $raw) {
      try {
        ChannelConfig::of(['chat_id' => '-100', 'channel_config' => $raw]);
        $this->fail('channel_config ' . var_export($raw, TRUE) . ' must fail');
      }
      catch (\CRM_Core_Exception $e) {
        $this->assertStringContainsString('channel_config must be a JSON object', $e->getMessage());
      }
    }
  }

  public function testAnEmptyChannelConfigAddsNothing(): void {
    foreach ([NULL, '', '  ', [], '{}'] as $raw) {
      $this->assertSame(['chat_id' => '-100'], ChannelConfig::of(['chat_id' => '-100', 'channel_config' => $raw]));
    }
  }

}
