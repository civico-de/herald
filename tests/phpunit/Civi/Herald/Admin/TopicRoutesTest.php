<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Api4\Herald;
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
      'channel_config' => ['custom' => 'x'],
      'future_option' => ['kept' => TRUE],
    ],
    'hooks' => ['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/x', 'webhook_headers' => []],
    'members' => ['delivery_mode' => 'group', 'group_id' => 4, 'respect_suspension' => FALSE],
  ];

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->set(Router::SETTING, self::CLI_MAP);
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
    self::call('saveTopic', ['topic' => 'operations', 'route' => self::CLI_MAP['ops'], 'previousName' => 'ops']);
    $map = \Civi::settings()->get(Router::SETTING);
    $this->assertIsArray($map);
    $this->assertSame(['default', 'operations', 'hooks', 'members'], array_keys($map));
  }

  public function testInvalidRoutesAreRejected(): void {
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'x', 'route' => ['channel' => 'carrier_pigeon']],
      [
        'The channel "carrier_pigeon" is not registered.',
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
        '"Parse Mode" must be one of: HTML, MarkdownV2, "".',
      ],
    );
    $this->assertRejected(
      'saveTopic',
      [
        'topic' => 'x',
        'route' => [
          'delivery_mode' => 'group',
          'webhook_headers' => ['a', 'b'],
          'email_to' => 5,
          'aggregate_minutes' => -1,
        ],
      ],
      [
        '"Extra headers" must be a JSON object.',
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
      ['topic' => 'hooks', 'route' => [], 'previousName' => 'ops'],
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

  public function testChannelsCarryTheirLabel(): void {
    $labels = array_column(self::call('getChannels'), 'label', 'key');
    $this->assertSame('Telegram', $labels['telegram']);
    $this->assertSame('Email', $labels['email']);
    $this->assertSame('CiviCRM activity', $labels['activity']);
  }

}
