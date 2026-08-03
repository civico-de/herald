<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for AbstractSend::buildChannelConfig.
 * @group unit
 */
class CRM_Herald_CivirulesActions_AbstractSendTest extends TestCase {

  public function testStripsRoutingKeys(): void {
    $config = CRM_Herald_CivirulesActions_AbstractSend::buildChannelConfig([
      'channel' => 'telegram',
      'template' => 'foo',
      'chat_id' => '-100',
    ]);
    $this->assertArrayNotHasKey('channel', $config);
    $this->assertArrayNotHasKey('template', $config);
    $this->assertSame('-100', $config['chat_id']);
  }

  public function testChannelConfigJsonMergedAndStripped(): void {
    $config = CRM_Herald_CivirulesActions_AbstractSend::buildChannelConfig([
      'channel' => 'webhook',
      'template' => 'foo',
      'channel_config' => '{"webhook_url": "https://example.com/x", "webhook_body_format": "slack"}',
    ]);
    $this->assertArrayNotHasKey('channel_config', $config);
    $this->assertSame('https://example.com/x', $config['webhook_url']);
    $this->assertSame('slack', $config['webhook_body_format']);
  }

  public function testChannelConfigArrayPassthrough(): void {
    $config = CRM_Herald_CivirulesActions_AbstractSend::buildChannelConfig([
      'channel' => 'ntfy',
      'template' => 'foo',
      'channel_config' => ['ntfy_topic' => 'donations', 'ntfy_priority' => 4],
    ]);
    $this->assertSame('donations', $config['ntfy_topic']);
    $this->assertSame(4, $config['ntfy_priority']);
  }

  public function testChannelConfigOverridesTopLevel(): void {
    // channel_config takes precedence — explicit per-channel overrides win.
    $config = CRM_Herald_CivirulesActions_AbstractSend::buildChannelConfig([
      'channel' => 'telegram',
      'template' => 'foo',
      'chat_id' => '-100',
      'channel_config' => ['chat_id' => '-200'],
    ]);
    $this->assertSame('-200', $config['chat_id']);
  }

  public function testFilterSubscriberSafeStripsRecipientAndCredentialKeys(): void {
    $filtered = CRM_Herald_CivirulesActions_AbstractSend::filterSubscriberSafe([
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
    $params = CRM_Herald_CivirulesActions_AbstractSend::applyDefaultEmailSubject(
      ['channel' => 'email'],
      '✅ 42,50 € — Stripe',
    );
    $this->assertSame('✅ 42,50 € — Stripe', $params['email_subject']);
  }

  public function testExplicitEmailSubjectWinsOverTemplateSubject(): void {
    $params = CRM_Herald_CivirulesActions_AbstractSend::applyDefaultEmailSubject(
      ['email_subject' => 'Manual subject'],
      'Template subject',
    );
    $this->assertSame('Manual subject', $params['email_subject']);
  }

  public function testNoTemplateSubjectLeavesParamsUntouched(): void {
    foreach ([NULL, ''] as $subject) {
      $params = CRM_Herald_CivirulesActions_AbstractSend::applyDefaultEmailSubject(['channel' => 'email'], $subject);
      $this->assertArrayNotHasKey('email_subject', $params);
    }
  }

  public function testInvalidChannelConfigJsonIsIgnored(): void {
    $config = CRM_Herald_CivirulesActions_AbstractSend::buildChannelConfig([
      'channel' => 'telegram',
      'template' => 'foo',
      'chat_id' => '-100',
      'channel_config' => 'not-json',
    ]);
    $this->assertSame('-100', $config['chat_id']);
    $this->assertArrayNotHasKey('channel_config', $config);
  }

}
