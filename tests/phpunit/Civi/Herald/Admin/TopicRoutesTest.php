<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Api4\Herald;
use Civi\Herald\ChannelFixture;
use Civi\Herald\Subscribers\SubscribableTopics;
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

    $this->assertSame([['topic' => 'new', 'route' => $route, 'label' => '', 'subscribable' => FALSE]], $rows);
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

  public function testContactsSubscribeToATopicTheAdminOffers(): void {
    self::call('saveTopic', [
      'topic' => 'news',
      'label' => ' News ',
      'subscribable' => TRUE,
      'route' => ['delivery_mode' => 'subscribers'],
    ]);
    $this->assertSame(
      ['delivery_mode' => 'subscribers', 'label' => 'News', 'subscribable' => TRUE],
      \Civi::settings()->get(Router::SETTING)['news'],
    );
    $this->assertSame(['news' => 'News'], self::options());

    \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addChain('subscription', \Civi\Api4\CustomValue::create('herald_subscription', FALSE)
        ->setValues(['entity_id' => '$id', 'channel' => 'email', 'topics' => ['news']]))
      ->execute();
    $usage = array_column(self::call('getTopicUsage'), 'subscribers', 'topic');
    $this->assertSame(1, $usage['news']);
    $this->assertSame(0, $usage['ops']);
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'headlines', 'route' => [], 'subscribable' => TRUE, 'previousName' => 'news'],
      ['The topic "news" cannot be renamed while 1 contacts subscribe to it.'],
    );

    self::call('saveTopic', ['topic' => 'news', 'route' => [], 'previousName' => 'news']);
    $this->assertSame([], self::options());
  }

  public function testTheKeysOfATopicFollowItsRules(): void {
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'news', 'route' => ['delivery_mode' => 'subscribers', 'label' => 'News']],
      [
        'label belong to the topic itself, not to its delivery settings.',
        'Delivery to subscribers reaches the contacts who subscribed to this topic',
      ],
    );
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'default', 'route' => self::CLI_MAP['default'], 'subscribable' => TRUE, 'previousName' => 'default'],
      ['Contacts cannot subscribe to "default"'],
    );
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'digest', 'route' => ['delivery_mode' => 'subscribers', 'subscribers_of' => 'ops']],
      ['Contacts cannot subscribe to the topic "ops".'],
    );
    $this->assertRejected(
      'saveTopic',
      [
        'topic' => 'default',
        'route' => ['delivery_mode' => 'subscribers', 'subscribers_of' => 'default'],
        'previousName' => 'default',
      ],
      ['Contacts cannot subscribe to the topic "default".'],
    );
    $this->assertSame(self::CLI_MAP, \Civi::settings()->get(Router::SETTING));
  }

  public function testARouteToTheSubscribersOfAnotherTopicUsesIt(): void {
    self::call('saveTopic', ['topic' => 'news', 'route' => [], 'subscribable' => TRUE]);
    self::call('saveTopic', [
      'topic' => 'digest',
      'route' => ['delivery_mode' => 'subscribers', 'subscribers_of' => 'news'],
    ]);
    $this->assertRejected('deleteTopic', ['topic' => 'news'], ['The topic "news" is still in use:', 'Topic "digest"']);

    $this->assertRejected(
      'saveTopic',
      ['topic' => 'news', 'route' => [], 'previousName' => 'news'],
      ['Contacts must be able to subscribe to "news" while it is in use:', 'Topic "digest"'],
    );

    self::call('deleteTopic', ['topic' => 'digest']);
    self::call('deleteTopic', ['topic' => 'news']);
    $this->assertSame([], self::options());
  }

  public function testTheRouteToSubscribersIsJudgedWithItsDefault(): void {
    self::call('saveTopic', ['topic' => 'news', 'route' => [], 'subscribable' => TRUE]);
    $map = \Civi::settings()->get(Router::SETTING);
    $map['default'] = ['delivery_mode' => 'subscribers', 'subscribers_of' => 'news'];
    \Civi::settings()->set(Router::SETTING, $map);
    self::call('saveTopic', ['topic' => 'digest', 'route' => ['delivery_mode' => 'subscribers']]);

    $map = \Civi::settings()->get(Router::SETTING);
    $map['default'] = ['delivery_mode' => 'subscribers'];
    \Civi::settings()->set(Router::SETTING, $map);
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'alerts', 'route' => []],
      ['Delivery to subscribers reaches the contacts who subscribed to this topic'],
    );
  }

  public function testAChangeToDefaultIsJudgedForTheTopicsItBacks(): void {
    $this->assertRejected(
      'saveTopic',
      ['topic' => 'default', 'route' => ['delivery_mode' => 'subscribers'], 'previousName' => 'default'],
      ['Topic "hooks": Delivery to subscribers reaches the contacts who subscribed to this topic'],
    );

    self::call('saveTopic', ['topic' => 'news', 'route' => [], 'subscribable' => TRUE]);
    self::call('saveTopic', [
      'topic' => 'default',
      'route' => ['delivery_mode' => 'subscribers', 'subscribers_of' => 'news'],
      'previousName' => 'default',
    ]);
    self::call('saveTopic', ['topic' => 'board', 'route' => [], 'subscribable' => TRUE]);

    $map = \Civi::settings()->get(Router::SETTING);
    $this->assertSame('news', Router::resolveFrom($map, 'hooks')['subscribers_of']);
    $this->assertSame('board', Router::resolveFrom($map, 'board')['subscribers_of']);

    $this->assertRejected(
      'saveTopic',
      ['topic' => 'board', 'route' => [], 'previousName' => 'board'],
      ['Without subscriptions, "board" would reach the subscribers of "news"'],
    );
    self::call('saveTopic', ['topic' => 'board', 'route' => ['subscribers_of' => 'news'], 'previousName' => 'board']);
  }

  public function testARouteNamingItsOwnSubscribersFollowsARename(): void {
    $route = ['delivery_mode' => 'subscribers', 'subscribers_of' => 'news'];
    self::call('saveTopic', ['topic' => 'news', 'route' => $route, 'subscribable' => TRUE]);
    self::call('saveTopic', [
      'topic' => 'headlines',
      'route' => $route,
      'subscribable' => TRUE,
      'previousName' => 'news',
    ]);
    $this->assertSame(
      ['delivery_mode' => 'subscribers', 'subscribers_of' => 'headlines', 'subscribable' => TRUE],
      \Civi::settings()->get(Router::SETTING)['headlines'],
    );
    $this->assertSame(['headlines' => 'headlines'], self::options());
  }

  public function testABufferedAlertToItsSubscribersKeepsATopic(): void {
    self::call('saveTopic', ['topic' => 'news', 'route' => [], 'subscribable' => TRUE]);
    (new \Civi\Herald\Buffer\AlertBuffer())->add(
      'grp-news',
      5,
      'body',
      ['delivery_mode' => 'subscribers', 'subscribers_of' => 'news'],
      NULL,
    );
    $this->assertRejected(
      'deleteTopic',
      ['topic' => 'news'],
      ['The topic "news" is still in use:', '1 buffered or parked alerts'],
    );
  }

  public function testTheBulkSettingOffersItsTopicsToo(): void {
    \Civi\Api4\Setting::set(FALSE)
      ->addValue(Router::SETTING, self::CLI_MAP + ['news' => ['subscribable' => TRUE, 'label' => 'News']])
      ->execute();
    $this->assertSame(['news' => 'News'], self::options());

    \Civi\Api4\Setting::set(FALSE)->addValue(Router::SETTING, self::CLI_MAP)->execute();
    $this->assertSame([], self::options());
  }

  public function testTopicsOfEveryDomainBecomeOptions(): void {
    $other = array_values(array_diff(
      \Civi\Api4\Domain::get(FALSE)->addSelect('id')->execute()->column('id'),
      [\CRM_Core_Config::domainID()],
    ));
    if ($other === []) {
      self::markTestSkipped('The test database has a single domain.');
    }
    $there = \Civi::settings((int) $other[0]);
    $saved = $there->get(Router::SETTING);
    $there->set(Router::SETTING, [
      'team' => ['subscribable' => TRUE, 'label' => 'Team'],
      'news' => ['subscribable' => TRUE, 'label' => 'Their news'],
    ]);
    try {
      self::call('saveTopic', ['topic' => 'news', 'route' => [], 'label' => 'News', 'subscribable' => TRUE]);
      $this->assertSame(['news' => 'News', 'team' => 'Team'], self::options());
    }
    finally {
      $there->set(Router::SETTING, $saved);
    }
  }

  /**
   * @return array<string, string>
   */
  private static function options(): array {
    $options = \Civi\Api4\OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', SubscribableTopics::OPTION_GROUP)
      ->execute()
      ->column('label', 'value');
    ksort($options);
    return $options;
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
