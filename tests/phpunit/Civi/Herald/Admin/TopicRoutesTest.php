<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Api4\Herald;
use Civi\Herald\ChannelFixture;
use Civi\Herald\Topic\Router;

/**
 * Herald.saveTopic / deleteTopic: the topic-routes screen's writes.
 *
 * @group headless
 */
class TopicRoutesTest extends AdminTestCase {

  /**
   * A map as an admin would set it with Setting.set, loose types included.
   */
  private const CLI_MAP = [
    'default' => ['channel' => 'email', 'email_to' => 'ops@example.org', 'aggregate_minutes' => '0'],
    'ops' => [
      'channel' => 'telegram',
      'chat_id' => '-100123',
      'disable_notification' => 1,
      'parse_mode' => NULL,
      'delivery_mode' => 'direct',
      'future_option' => ['kept' => TRUE],
    ],
    'hooks' => [
      'channel' => 'webhook',
      'webhook_url' => 'https://hooks.example.org/x',
      'webhook_body_format' => 'slack',
    ],
    'members' => ['delivery_mode' => 'group', 'group_id' => 4, 'respect_suspension' => FALSE],
  ];

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->set(Router::SETTING, self::CLI_MAP);
    ChannelFixture::add('telegram', 'telegram', ['bot_token' => bin2hex(random_bytes(8))]);
    ChannelFixture::add('ntfy', 'ntfy');
    ChannelFixture::add('webhook', 'webhook');
  }

  public function testValidRouteIsStoredAsGiven(): void {
    $route = ['channel' => 'ntfy', 'ntfy_topic' => 'alerts', 'ntfy_priority' => 4, 'aggregate_minutes' => 15];
    $rows = self::call('saveTopic', ['topic' => 'new', 'route' => $route]);

    $this->assertSame([['topic' => 'new', 'route' => $route]], $rows);
    $this->assertSame(self::CLI_MAP + ['new' => $route], \Civi::settings()->get(Router::SETTING));
    $this->assertSame('ntfy', Router::resolveFrom(self::CLI_MAP + ['new' => $route], 'new')['channel']);
  }

  public function testExistingRoutesSurviveARoundTrip(): void {
    foreach (self::CLI_MAP as $topic => $route) {
      self::call('saveTopic', ['topic' => $topic, 'route' => $route, 'previousName' => $topic]);
    }
    $this->assertSame(self::CLI_MAP, \Civi::settings()->get(Router::SETTING));
  }

  public function testRenameKeepsThePosition(): void {
    self::call('saveTopic', ['topic' => 'webhooks', 'route' => self::CLI_MAP['hooks'], 'previousName' => 'hooks']);
    $map = \Civi::settings()->get(Router::SETTING);
    $this->assertIsArray($map);
    $this->assertSame(['default', 'ops', 'webhooks', 'members'], array_keys($map));
  }

  public function testATopicInUseIsNeitherRenamedNorDeleted(): void {
    // The monitor publishes to "ops" by default.
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'operations', 'route' => self::CLI_MAP['ops'], 'previousName' => 'ops'],
      ['The topic "ops" cannot be renamed while in use:', 'The monitor'],
    );
    $this->assertRejected('deleteTopic', ['topic' => 'ops'], ['The topic "ops" is still in use:', 'The monitor']);
    $this->assertSame(self::CLI_MAP, \Civi::settings()->get(Router::SETTING));
  }

  public function testTheUsageListsWhoNamesEachTopic(): void {
    $usage = array_column(self::call('getTopicUsage'), 'used_by', 'topic');
    $this->assertSame(['The monitor'], $usage['ops']);
    $this->assertSame([], $usage['hooks']);
  }

  public function testInvalidRoutesAreRejected(): void {
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'x', 'route' => ['channel' => 'carrier_pigeon', 'bot_token' => bin2hex(random_bytes(8))]],
      [
        'There is no channel "carrier_pigeon".',
        'bot_token belong to the channel, not to a delivery; set them under Channels.',
      ],
    );
    $this->assertRejected(
      'saveTopic',
      [
        'topic' => ' x',
        'route' => ['aggregate_minutes' => 'soon', 'disable_notification' => 'maybe', 'parse_mode' => 'Markdown'],
      ],
      [
        'without leading or trailing spaces',
        '"Aggregate window in minutes" must be a whole number.',
        '"Silent message" must be yes or no.',
        '"Parse Mode" must be one of: HTML, MarkdownV2, text.',
      ],
    );
    $this->assertRejected(
      'saveTopic',
      [
        'topic' => 'x',
        'route' => [
          'delivery_mode' => 'group',
          'ntfy_priority' => 'loud',
          'email_to' => 5,
          'aggregate_minutes' => -1,
        ],
      ],
      [
        '"Priority (1-5)" must be a whole number.',
        '"Recipient address" must be text.',
        '"Aggregate window in minutes" must be at least 0.',
        'The delivery mode "group" needs "Group".',
      ],
    );
    $this->assertSame(self::CLI_MAP, \Civi::settings()->get(Router::SETTING));
  }

  public function testNameClashesAreRejected(): void {
    $this->assertRejected('saveTopic', ['topic' => 'ops', 'route' => []], ['A topic named "ops" already exists.']);
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'hooks', 'route' => [], 'previousName' => 'members'],
      [
        'A topic named "hooks" already exists.',
      ],
    );
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'gone', 'route' => [], 'previousName' => 'gone'],
      [
        'The topic "gone" no longer exists.',
      ],
    );
  }

  public function testDeleteRemovesOneTopic(): void {
    $this->assertSame([['topic' => 'hooks']], self::call('deleteTopic', ['topic' => 'hooks']));
    $expected = self::CLI_MAP;
    unset($expected['hooks']);
    $this->assertSame($expected, \Civi::settings()->get(Router::SETTING));
    $this->assertRejected('deleteTopic', ['topic' => 'hooks'], ['The topic "hooks" does not exist.']);
  }

  public function testOnlyAdministratorsMayWrite(): void {
    $this->assertAdminOnly(static fn () => Herald::saveTopic()->setTopic('x')->execute());
    $this->assertAdminOnly(static fn () => Herald::deleteTopic()->setTopic('ops')->execute());
  }

  public function testChannelTypesCarryTheirLabel(): void {
    $labels = array_column(self::call('getChannelTypes'), 'label', 'key');
    $this->assertSame('Telegram', $labels['telegram']);
    $this->assertSame('Email', $labels['email']);
    $this->assertSame('CiviCRM activity', $labels['activity']);
  }

}
