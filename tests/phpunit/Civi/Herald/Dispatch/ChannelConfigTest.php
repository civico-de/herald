<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\HeadlessSite;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * How caller params become a channel config. Headless: the channel schemas
 * carry translated labels.
 * @group headless
 */
class ChannelConfigTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return HeadlessSite::standard()->apply();
  }

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

  public function testStripsTheTopic(): void {
    $this->assertSame(['ntfy_priority' => 4], ChannelConfig::of(['topic' => 'ops', 'ntfy_priority' => 4]));
  }

  public function testDeliveryKeysOverrideTheStoredDefaults(): void {
    $stored = bin2hex(random_bytes(8));
    $config = ChannelConfig::merged(
      ChannelRegistry::default()->get('telegram'),
      ['bot_token' => $stored, 'chat_id' => '-100', 'parse_mode' => 'HTML'],
      ['chat_id' => '-200'],
    );
    $this->assertSame(['bot_token' => $stored, 'chat_id' => '-200', 'parse_mode' => 'HTML'], $config);
  }

  public function testARenderedSubjectBeatsTheStoredDefaultSubject(): void {
    $email = ChannelRegistry::default()->get('email');
    $this->assertSame(
      'Weekly digest',
      ChannelConfig::merged(
        $email,
        ['email_subject' => 'CiviCRM alert'],
        [ChannelSubject::KEY => 'Weekly digest'],
      )['email_subject'],
    );
    $this->assertSame(
      'CiviCRM alert',
      ChannelConfig::merged($email, ['email_subject' => 'CiviCRM alert'], [])['email_subject'],
    );
  }

  public function testBlankDeliveryValuesKeepTheStoredDefault(): void {
    $config = ChannelConfig::merged(
      ChannelRegistry::default()->get('ntfy'),
      ['ntfy_topic' => 'ops'],
      ['ntfy_topic' => '', 'ntfy_title' => NULL],
    );
    $this->assertSame(['ntfy_topic' => 'ops'], $config);
  }

  public function testAChannelKeyInADeliveryFails(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('bot_token belong to the channel configuration');
    ChannelConfig::merged(
      ChannelRegistry::default()->get('telegram'),
      [],
      ['bot_token' => bin2hex(random_bytes(8)), 'chat_id' => '1'],
    );
  }

  public function testOwnWebhookUrlDropsTheSecretsBoundToTheStoredOne(): void {
    $stored = [
      'webhook_url' => 'https://hooks.example.org/a',
      'webhook_auth_token' => bin2hex(random_bytes(8)),
      'webhook_headers' => ['X-Key' => 'k'],
      'webhook_body_format' => 'slack',
    ];
    $channel = ChannelRegistry::default()->get('webhook');
    $this->assertSame(
      ['webhook_url' => 'https://other.example.org/b', 'webhook_body_format' => 'slack'],
      ChannelConfig::merged($channel, $stored, ['webhook_url' => 'https://other.example.org/b']),
    );
    $this->assertSame($stored, ChannelConfig::merged($channel, $stored, [
      'webhook_url' => 'https://hooks.example.org/a',
    ]));
  }

  public function testTheSubjectIsNamedForTheChannel(): void {
    $config = ChannelConfig::merged(
      ChannelRegistry::default()->get('email'),
      ['email_from' => 'a@example.org'],
      ChannelSubject::withSubject([], 'Hello'),
    );
    $this->assertSame(['email_from' => 'a@example.org', 'email_subject' => 'Hello'], $config);
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
      'subscribers_of' => 'completed_alert',
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

  /**
   * @return array<string, array{string, string}>
   */
  public static function subjectKeys(): array {
    return [
      'email' => ['email', 'email_subject'],
      'activity' => ['activity', 'activity_subject'],
      'ntfy' => ['ntfy', 'ntfy_title'],
      'pushover' => ['pushover', 'pushover_title'],
    ];
  }

  /**
   * @dataProvider subjectKeys
   */
  public function testTheTemplateSubjectReachesEachChannelUnderItsOwnKey(string $channel, string $key): void {
    $config = ChannelSubject::forChannel(
      ChannelRegistry::default()->get($channel),
      ChannelSubject::withSubject(['channel' => $channel], '✅ 42,50 € — Stripe'),
    );
    $this->assertSame('✅ 42,50 € — Stripe', $config[$key]);
    $this->assertArrayNotHasKey(ChannelSubject::KEY, $config);
  }

  public function testAChannelWithoutASubjectGetsNone(): void {
    $config = ChannelSubject::forChannel(
      ChannelRegistry::default()->get('telegram'),
      ChannelSubject::withSubject(['chat_id' => '42'], 'Template subject'),
    );
    $this->assertSame(['chat_id' => '42'], $config);
  }

  public function testExplicitSubjectKeyWinsOverTemplateSubject(): void {
    $config = ChannelSubject::forChannel(
      ChannelRegistry::default()->get('activity'),
      ChannelSubject::withSubject(['activity_subject' => 'Manual subject'], 'Template subject'),
    );
    $this->assertSame('Manual subject', $config['activity_subject']);
  }

  public function testNoTemplateSubjectLeavesParamsUntouched(): void {
    foreach ([NULL, ''] as $subject) {
      $this->assertSame(['channel' => 'email'], ChannelSubject::withSubject(['channel' => 'email'], $subject));
    }
  }

}
