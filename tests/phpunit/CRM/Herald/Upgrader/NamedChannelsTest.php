<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelInterface;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Channel\TelegramChannel;
use Civi\Herald\Dispatch\ChannelSender;
use Civi\Herald\Topic\Router;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Channel settings become named channels, and every stored delivery names one.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_NamedChannelsTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \Civi::service('crypto.registry')->addSymmetricKey([
      'key' => random_bytes(32),
      'suite' => 'aes-cbc',
      'tags' => ['CRED'],
      'weight' => -1,
    ]);
    // A site before the upgrade: no channels, and the settings they come from.
    foreach (CRM_Herald_Upgrader_Domain::ids() as $domainId) {
      \Civi::settings($domainId)->revert(ChannelStore::SETTING);
    }
  }

  /**
   * @param array<string, string> $settings
   */
  private static function oldSettings(array $settings, ?int $domainId = NULL): void {
    foreach ($settings as $name => $value) {
      CRM_Core_DAO::executeQuery(
        'INSERT INTO civicrm_setting (name, value, domain_id, is_domain) VALUES (%1, %2, %3, 1)',
        [
          1 => [$name, 'String'],
          2 => [serialize($value), 'String'],
          3 => [$domainId ?? CRM_Core_Config::domainID(), 'Integer'],
        ],
      );
    }
  }

  /**
   * @return array<string, array{type: string, subscribable: bool, config: array<string, mixed>}>
   */
  private static function channels(?int $domainId = NULL): array {
    $store = new ChannelStore(NULL, \Civi::settings($domainId));
    $out = [];
    foreach ($store->all() as $name => $record) {
      $out[$name] = [
        'type' => $record['type'],
        'subscribable' => $record['subscribable'],
        'config' => $store->get($name)['config'],
      ];
    }
    ksort($out);
    return $out;
  }

  private static function otherDomain(): int {
    $other = array_values(array_diff(CRM_Herald_Upgrader_Domain::ids(), [CRM_Core_Config::domainID()]));
    if ($other === []) {
      self::markTestSkipped('The test database has a single domain.');
    }
    return $other[0];
  }

  public function testEachDomainKeepsTheWebhookItSentTo(): void {
    $other = self::otherDomain();
    self::oldSettings(['herald_webhook_default_url' => 'https://hooks.example.org/one']);
    self::oldSettings(['herald_webhook_default_url' => 'https://hooks.example.org/two'], $other);
    $token = bin2hex(random_bytes(8));
    \Civi::settings($other)->set(Router::SETTING, ['default' => [
      'channel' => 'webhook',
      'webhook_auth_token' => $token,
    ]]);
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_herald_buffer (group_hash, window_minutes, body, delivery, created_date)
       VALUES ('h', 5, 'Hi', %1, NOW())",
      [1 => [json_encode(['channel' => 'webhook', 'webhook_auth_token' => $token]), 'String']],
    );
    $alert = (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');

    CRM_Herald_Upgrader_NamedChannels::migrate();
    $state = static fn (): array => [
      self::channels(),
      self::channels($other),
      \Civi::settings($other)->get(Router::SETTING),
      CRM_Core_DAO::singleValueQuery('SELECT delivery FROM civicrm_herald_buffer WHERE id = %1', [1 => [
        $alert,
        'Integer',
      ]]),
    ];
    $before = $state();
    $again = CRM_Herald_Upgrader_NamedChannels::migrate();

    $this->assertSame(0, $again['channels']);
    $this->assertSame($before, $state());
    $expected = static fn (string $url): array => ['webhook_auth_token' => $token, 'webhook_url' => $url];
    // Rule actions, jobs and alerts may be sent from either domain.
    $this->assertSame(
      $expected('https://hooks.example.org/one'),
      self::channels()['webhook_alert_' . $alert]['config'],
    );
    $this->assertSame(
      $expected('https://hooks.example.org/two'),
      self::channels($other)['webhook_alert_' . $alert]['config'],
    );
    $this->assertSame(
      ['channel' => 'webhook_alert_' . $alert],
      json_decode(
        (string) CRM_Core_DAO::singleValueQuery(
          'SELECT delivery FROM civicrm_herald_buffer WHERE id = %1',
          [1 => [$alert, 'Integer']],
        ),
        TRUE,
      ),
    );
    // A route belongs to its own domain.
    $this->assertSame(['default' => ['channel' => 'webhook_topic_1']], \Civi::settings($other)->get(Router::SETTING));
    $this->assertSame($expected('https://hooks.example.org/two'), self::channels($other)['webhook_topic_1']['config']);
    $this->assertArrayNotHasKey('webhook_topic_1', self::channels());
    $this->assertSame('https://hooks.example.org/two', self::channels($other)['webhook']['config']['webhook_url']);
  }

  public function testTheSettingsBecomeChannelsNamedLikeTheirType(): void {
    $bot = bin2hex(random_bytes(8));
    self::oldSettings([
      'herald_telegram_bot_token' => $bot,
      'herald_telegram_default_chat_id' => '-100',
      'herald_webhook_default_auth_token' => bin2hex(random_bytes(8)),
      'herald_ntfy_server' => 'https://ntfy.example.org',
      'herald_pushover_default_user_key' => 'u1',
      'herald_email_from' => ' Herald <h@example.org> ',
    ]);

    $moved = CRM_Herald_Upgrader_NamedChannels::migrate();

    $this->assertSame(
      [
        'activity' => ['type' => 'activity', 'subscribable' => FALSE, 'config' => []],
        'email' => ['type' => 'email', 'subscribable' => TRUE, 'config' => ['email_from' => 'Herald <h@example.org>']],
        'ntfy' => ['type' => 'ntfy', 'subscribable' => TRUE, 'config' => ['ntfy_server' => 'https://ntfy.example.org']],
        'telegram' => [
          'type' => 'telegram',
          'subscribable' => TRUE,
          'config' => ['bot_token' => $bot, 'chat_id' => '-100'],
        ],
        'webhook' => ['type' => 'webhook', 'subscribable' => TRUE, 'config' => []],
      ],
      self::channels(),
    );
    // The other domain had none of the settings and gets the channels sent without them.
    $this->assertSame(['activity', 'email', 'ntfy', 'webhook'], array_keys(self::channels(self::otherDomain())));
    $this->assertSame(5 + 4, $moved['channels']);
    $raw = \Civi::settings()->get(ChannelStore::SETTING);
    $this->assertNotSame($bot, $raw['telegram']['config']['bot_token']);
    $this->assertSame(0, (int) CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM civicrm_setting WHERE name IN ('
      . implode(', ', array_map(
        static fn (string $name): string => "'{$name}'",
        array_keys(CRM_Herald_Upgrader_OldSettings::MAP),
      ))
      . ')',
    ));
  }

  /**
   * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
   */
  public static function deliveries(): array {
    return [
      'no channel went to Telegram' => [['chat_id' => '-1'], ['chat_id' => '-1', 'channel' => 'telegram']],
      'channel_config merges in' => [
        ['channel' => 'ntfy', 'channel_config' => '{"ntfy_tags": "chart", "ntfy_priority": 4}'],
        ['channel' => 'ntfy', 'ntfy_tags' => 'chart', 'ntfy_priority' => 4],
      ],
      'empty parse mode is plain text' => [
        ['channel' => 'telegram', 'parse_mode' => ''],
        ['channel' => 'telegram', 'parse_mode' => 'text'],
      ],
      'an address list goes through email' => [
        ['delivery_mode' => 'addresses', 'email_addresses' => 'a@example.org'],
        ['delivery_mode' => 'addresses', 'email_addresses' => 'a@example.org', 'channel' => 'email'],
      ],
      'a topic needs no channel' => [['topic' => 'ops'], ['topic' => 'ops']],
      'subscribers pick their own' => [['delivery_mode' => 'subscribers'], ['delivery_mode' => 'subscribers']],
    ];
  }

  /**
   * @dataProvider deliveries
   * @param array<string, mixed> $old
   * @param array<string, mixed> $new
   */
  public function testEachDeliveryNamesAChannel(array $old, array $new): void {
    $this->assertSame($new, CRM_Herald_Upgrader_NamedChannels::delivery(
      $old,
      'action_1',
      [CRM_Herald_Upgrader_Domain::current()],
    ));
  }

  public function testWebhookSecretsMoveIntoAChannelThatIdenticalDeliveriesShare(): void {
    $token = bin2hex(random_bytes(8));
    $hook = ['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/a', 'webhook_auth_token' => $token];

    $first = CRM_Herald_Upgrader_NamedChannels::delivery($hook, 'action_1', [CRM_Herald_Upgrader_Domain::current()]);
    $same = CRM_Herald_Upgrader_NamedChannels::delivery($hook, 'alert_7', [CRM_Herald_Upgrader_Domain::current()]);
    $other = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['webhook_auth_token' => bin2hex(random_bytes(8))] + $hook,
      'job_3',
      [CRM_Herald_Upgrader_Domain::current()],
    );

    $this->assertSame(['channel' => 'webhook_action_1', 'webhook_url' => 'https://hooks.example.org/a'], $first);
    $this->assertSame('webhook_action_1', $same['channel']);
    $this->assertSame('webhook_job_3', $other['channel']);
    $this->assertSame(
      ['webhook_auth_token' => $token, 'webhook_url' => 'https://hooks.example.org/a'],
      self::channels()['webhook_action_1']['config'],
    );
  }

  public function testATokenWithoutAUrlKeepsTheDefaultUrlItWentTo(): void {
    $token = bin2hex(random_bytes(8));
    $params = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['channel' => 'webhook', 'webhook_auth_token' => $token],
      'action_2',
      [CRM_Herald_Upgrader_Domain::current('https://hooks.example.org/default')],
    );

    $this->assertSame(['channel' => 'webhook_action_2'], $params);
    $this->assertSame(
      ['webhook_auth_token' => $token, 'webhook_url' => 'https://hooks.example.org/default'],
      self::channels()['webhook_action_2']['config'],
    );
  }

  public function testATopicTokenStaysWithTheUrlItInherits(): void {
    $token = bin2hex(random_bytes(8));
    \Civi::settings()->set(Router::SETTING, [
      'default' => ['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/ops'],
      'secure' => ['channel_config' => ['webhook_auth_token' => $token]],
    ]);

    CRM_Herald_Upgrader_StoredRoutes::rewrite(CRM_Herald_Upgrader_Domain::current('https://hooks.example.org/global'));

    $routes = \Civi::settings()->get(Router::SETTING);
    $this->assertSame(['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/ops'], $routes['default']);
    $this->assertSame(['channel' => 'webhook_topic_2'], $routes['secure']);
    $this->assertSame(
      ['webhook_auth_token' => $token, 'webhook_url' => 'https://hooks.example.org/ops'],
      self::channels()['webhook_topic_2']['config'],
    );
  }

  public function testARouteWithoutAChannelKeepsTelegramWhenThereIsNoDefaultRoute(): void {
    \Civi::settings()->set(Router::SETTING, ['ops' => ['chat_id' => '-100']]);

    CRM_Herald_Upgrader_StoredRoutes::rewrite(CRM_Herald_Upgrader_Domain::current());

    $this->assertSame(
      ['ops' => ['chat_id' => '-100', 'channel' => 'telegram']],
      \Civi::settings()->get(Router::SETTING),
    );
  }

  public function testARouteWithItsOwnUrlKeepsTheTokenItInherited(): void {
    $token = bin2hex(random_bytes(8));
    \Civi::settings()->set(Router::SETTING, [
      'default' => [
        'channel' => 'webhook',
        'webhook_url' => 'https://hooks.example.org/a',
        'webhook_auth_token' => $token,
      ],
      'ops' => ['webhook_url' => 'https://hooks.example.org/ops'],
    ]);

    CRM_Herald_Upgrader_StoredRoutes::rewrite(CRM_Herald_Upgrader_Domain::current());

    $routes = \Civi::settings()->get(Router::SETTING);
    $this->assertSame(
      ['webhook_url' => 'https://hooks.example.org/ops', 'channel' => 'webhook_topic_2'],
      $routes['ops'],
    );
    $this->assertSame(
      ['webhook_auth_token' => $token, 'webhook_url' => 'https://hooks.example.org/ops'],
      self::channels()['webhook_topic_2']['config'],
    );
  }

  public function testARouteWithItsOwnChannelConfigInheritsNothingFromTheDefaults(): void {
    \Civi::settings()->set(Router::SETTING, [
      'default' => [
        'channel' => 'webhook',
        'channel_config' => [
          'webhook_url' => 'https://hooks.example.org/a',
          'webhook_auth_token' => bin2hex(random_bytes(8)),
        ],
      ],
      'ops' => ['channel_config' => ['webhook_url' => 'https://hooks.example.org/ops']],
      'json' => ['channel_config' => ['webhook_body_format' => 'json']],
    ]);

    CRM_Herald_Upgrader_StoredRoutes::rewrite(CRM_Herald_Upgrader_Domain::current('https://hooks.example.org/setting'));

    $routes = \Civi::settings()->get(Router::SETTING);
    $this->assertSame(['webhook_url' => 'https://hooks.example.org/ops', 'channel' => 'webhook'], $routes['ops']);
    // Empty, the URL comes from the channel: the old default URL setting.
    $this->assertSame(['webhook_body_format' => 'json', 'channel' => 'webhook', 'webhook_url' => ''], $routes['json']);
    $this->assertSame(
      ['webhook_topic_1'],
      array_keys(array_filter(
        self::channels(),
        static fn (string $name): bool => str_starts_with($name, 'webhook_'),
        ARRAY_FILTER_USE_KEY,
      )),
    );
  }

  public function testATokenThatHadNoUrlToGoToIsDropped(): void {
    $params = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['channel' => 'webhook', 'webhook_auth_token' => bin2hex(random_bytes(8))],
      'action_3',
      [CRM_Herald_Upgrader_Domain::current()],
    );
    $this->assertSame(['channel' => 'webhook'], $params);
    $this->assertArrayNotHasKey('webhook_action_3', self::channels());
  }

  public function testHeadersGivenAsTextMoveIntoTheChannel(): void {
    $params = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/a', 'webhook_headers' => '{"X-Key":"k"}'],
      'job_4',
      [CRM_Herald_Upgrader_Domain::current()],
    );
    $this->assertSame('webhook_job_4', $params['channel']);
    $this->assertSame(['X-Key' => 'k'], self::channels()['webhook_job_4']['config']['webhook_headers']);
  }

  public function testHeadersTheChannelRejectsLeaveItSwitchedOff(): void {
    foreach (['"k"', '{broken'] as $headers) {
      $params = CRM_Herald_Upgrader_NamedChannels::delivery(
        ['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/a', 'webhook_headers' => $headers],
        'job_1',
        [CRM_Herald_Upgrader_Domain::current()],
      );
      $this->assertSame(
        ['channel' => 'webhook_job_1', 'webhook_url' => 'https://hooks.example.org/a'],
        $params,
      );
      $record = \Civi::settings()->get(ChannelStore::SETTING)['webhook_job_1'];
      $this->assertFalse($record['active']);
      $this->assertSame(['webhook_url'], array_keys($record['config']));
    }
  }

  public function testDeliveriesWithEqualRejectedHeadersShareTheSwitchedOffChannel(): void {
    $delivery = ['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/a', 'webhook_headers' => '"k"'];
    $domains = [CRM_Herald_Upgrader_Domain::current()];

    $action = CRM_Herald_Upgrader_NamedChannels::delivery($delivery, 'action_1', $domains);
    $alert = CRM_Herald_Upgrader_NamedChannels::delivery($delivery, 'alert_1', $domains);

    $this->assertSame('webhook_action_1', $action['channel']);
    $this->assertSame('webhook_action_1', $alert['channel']);
  }

  public function testOtherChannelKeysLeaveTheDelivery(): void {
    $this->assertSame(
      ['channel' => 'email', 'email_to' => 'ops@example.org'],
      CRM_Herald_Upgrader_NamedChannels::delivery(
        ['channel' => 'email', 'email_from' => 'Ops <o@example.org>', 'email_to' => 'ops@example.org'],
        'action_1',
        [CRM_Herald_Upgrader_Domain::current()],
      ),
    );
  }

  public function testATokenGivenAsANumberMovesIntoTheChannel(): void {
    $token = random_int(100_000, 999_999);
    $params = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/a', 'webhook_auth_token' => $token],
      'job_1',
      [CRM_Herald_Upgrader_Domain::current()],
    );

    $this->assertSame((string) $token, self::channels()[$params['channel']]['config']['webhook_auth_token']);
  }

  public function testAGlobalDeliveryGetsAChannelWhereverItHadAUrl(): void {
    $other = self::otherDomain();
    $token = bin2hex(random_bytes(8));
    $domains = [
      CRM_Herald_Upgrader_Domain::current('https://hooks.example.org/default'),
      new CRM_Herald_Upgrader_Domain($other, ''),
    ];

    $own = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/own', 'webhook_auth_token' => $token],
      'action_1',
      $domains,
    );
    $default = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['channel' => 'webhook', 'webhook_auth_token' => $token],
      'action_2',
      $domains,
    );

    $expected = ['webhook_auth_token' => $token, 'webhook_url' => 'https://hooks.example.org/own'];
    $this->assertSame($expected, self::channels()[$own['channel']]['config']);
    $this->assertSame($expected, self::channels($other)[$own['channel']]['config']);
    $this->assertArrayHasKey($default['channel'], self::channels());
    // That domain had no URL to send to, so the delivery failed there before.
    $this->assertArrayNotHasKey($default['channel'], self::channels($other));
  }

  public function testAJobGetsItsChannelInItsOwnDomainOnly(): void {
    $other = self::otherDomain();
    $job = \Civi\Api4\Job::create(checkPermissions: FALSE)
      ->setValues([
        'domain_id' => $other,
        'name' => 'herald test job of the other domain',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'send',
        'parameters' => json_encode([
          'version' => 4,
          'channel' => 'webhook',
          'message' => 'ping',
          'config' => [
            'webhook_url' => 'https://hooks.example.org/ping',
            'webhook_auth_token' => bin2hex(random_bytes(8)),
          ],
        ]),
      ])
      ->execute()
      ->first()['id'];

    CRM_Herald_Upgrader_StoredDeliveries::rewrite(
      [CRM_Herald_Upgrader_Domain::current(), new CRM_Herald_Upgrader_Domain($other, '')],
      CRM_Herald_Upgrader_NamedChannels::ruleAction(...),
      CRM_Herald_Upgrader_NamedChannels::delivery(...),
      CRM_Herald_Upgrader_StoredRoutes::rewrite(...),
    );

    $this->assertArrayHasKey('webhook_job_' . $job, self::channels($other));
    $this->assertArrayNotHasKey('webhook_job_' . $job, self::channels());
  }

  public function testAChatIdFromTheSettingsFileGivenAsANumberIsKept(): void {
    \Civi::settings()->loadMandatory([
      'herald_telegram_bot_token' => bin2hex(random_bytes(8)),
      'herald_telegram_default_chat_id' => -100_123,
    ]);
    try {
      CRM_Herald_Upgrader_SettingChannels::create(
        CRM_Core_Config::domainID(),
        CRM_Herald_Upgrader_OldSettings::read()[CRM_Core_Config::domainID()],
      );
    }
    finally {
      \Civi::service('settings_manager')->flush();
    }

    $this->assertSame('-100123', self::channels()['telegram']['config']['chat_id']);
  }

  public function testTheDefaultTokenGoesOnlyWithTheDefaultUrl(): void {
    $token = bin2hex(random_bytes(8));
    $domains = [CRM_Herald_Upgrader_Domain::current('https://hooks.example.org/default', $token)];
    $headers = ['X-Key' => 'k'];

    $default = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['channel' => 'webhook', 'webhook_headers' => $headers],
      'action_1',
      $domains,
    );
    $own = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['channel' => 'webhook', 'webhook_url' => 'https://hooks.example.org/own', 'webhook_headers' => $headers],
      'action_2',
      $domains,
    );

    $this->assertSame(
      [
        'webhook_headers' => $headers,
        'webhook_auth_token' => $token,
        'webhook_url' => 'https://hooks.example.org/default',
      ],
      self::channels()[$default['channel']]['config'],
    );
    $this->assertSame(
      ['webhook_headers' => $headers, 'webhook_url' => 'https://hooks.example.org/own'],
      self::channels()[$own['channel']]['config'],
    );
  }

  public function testARouteWithItsOwnChannelConfigKeepsTheChannelsChat(): void {
    \Civi::settings()->set(Router::SETTING, [
      'default' => ['channel' => 'telegram', 'channel_config' => ['chat_id' => '-1']],
      'ops' => ['channel_config' => ['parse_mode' => 'HTML']],
    ]);

    CRM_Herald_Upgrader_StoredRoutes::rewrite(CRM_Herald_Upgrader_Domain::current());

    $this->assertSame(
      ['parse_mode' => 'HTML', 'chat_id' => ''],
      \Civi::settings()->get(Router::SETTING)['ops'],
    );
  }

  public function testASettingFromTheSettingsFileBecomesAChannel(): void {
    $bot = bin2hex(random_bytes(8));
    \Civi::settings()->loadMandatory(['herald_telegram_bot_token' => $bot]);
    try {
      $this->assertSame(
        $bot,
        CRM_Herald_Upgrader_OldSettings::read()[CRM_Core_Config::domainID()]['herald_telegram_bot_token'],
      );
    }
    finally {
      \Civi::service('settings_manager')->flush();
    }
  }

  public function testASendJobKeepsItsWebhookToken(): void {
    $token = bin2hex(random_bytes(8));
    $job = \Civi\Api4\Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald test send job',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'send',
        'parameters' => json_encode([
          'version' => 4,
          'channel' => 'webhook',
          'message' => 'ping',
          'config' => ['webhook_url' => 'https://hooks.example.org/ping', 'webhook_auth_token' => $token],
        ]),
      ])
      ->execute()
      ->first()['id'];

    CRM_Herald_Upgrader_StoredDeliveries::rewrite(
      [CRM_Herald_Upgrader_Domain::current()],
      CRM_Herald_Upgrader_NamedChannels::ruleAction(...),
      CRM_Herald_Upgrader_NamedChannels::delivery(...),
      CRM_Herald_Upgrader_StoredRoutes::rewrite(...),
    );

    $parameters = json_decode(
      (string) \Civi\Api4\Job::get(checkPermissions: FALSE)
        ->addWhere('id', '=', $job)
        ->execute()
        ->first()['parameters'],
      TRUE,
    );
    $this->assertSame('webhook_job_' . $job, $parameters['channel']);
    $this->assertSame(['webhook_url' => 'https://hooks.example.org/ping'], $parameters['config']);
    $this->assertSame($token, self::channels()['webhook_job_' . $job]['config']['webhook_auth_token']);
  }

  public function testStoredDeliveriesAreRewritten(): void {
    $bot = bin2hex(random_bytes(8));
    self::oldSettings(['herald_telegram_bot_token' => $bot]);
    $handler = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civirule_action WHERE class_name = 'CRM_Herald_CivirulesAdapter_Action_Notification'",
    );
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule (name, label, trigger_id, is_active, created_date)
       VALUES ('herald_test_upgrade', 'Upgrade', (SELECT MIN(id) FROM civirule_trigger), 1, NOW())",
    );
    CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_action (rule_id, action_id, action_params) VALUES (LAST_INSERT_ID(), %1, %2)',
      [
        1 => [$handler, 'Integer'],
        2 => [serialize(['chat_id' => '-5', 'parse_mode' => '', 'disable_notification' => FALSE]), 'String'],
      ],
    );
    $action = (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    $job = \Civi\Api4\Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald test upgrade',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode([
          'version' => 4,
          'actionParams' => [
            'digest' => 'd',
            'delivery_mode' => 'addresses',
            'channel_config' => ['email_to' => 'x@example.org'],
          ],
        ]),
      ])
      ->execute()
      ->first()['id'];
    \Civi::settings()->set(Router::SETTING, [
      'default' => ['channel' => 'ntfy'],
      'quiet' => ['chat_id' => '-9'],
      'loud' => ['channel' => 'telegram'],
    ]);
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_herald_buffer (group_hash, window_minutes, body, delivery, created_date)
       VALUES ('h', 5, 'Hi <b>there</b>', %1, NOW())",
      [1 => [json_encode(['chat_id' => '-7']), 'String']],
    );
    $alert = (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');

    $moved = CRM_Herald_Upgrader_NamedChannels::migrate();

    $this->assertGreaterThanOrEqual(5, $moved['deliveries']);
    $this->assertSame(['chat_id' => '-5', 'parse_mode' => 'text', 'channel' => 'telegram'], unserialize(
      (string) CRM_Core_DAO::singleValueQuery('SELECT action_params FROM civirule_rule_action WHERE id = %1', [1 => [
        $action,
        'Integer',
      ]]),
      ['allowed_classes' => FALSE],
    ));
    $parameters = \Civi\Api4\Job::get(checkPermissions: FALSE)
      ->addWhere('id', '=', $job)
      ->execute()
      ->first()['parameters'];
    $this->assertSame(
      ['digest' => 'd', 'delivery_mode' => 'addresses', 'email_to' => 'x@example.org', 'channel' => 'email'],
      json_decode((string) $parameters, TRUE)['actionParams'],
    );
    $this->assertSame(
      [
        'default' => ['channel' => 'ntfy'],
        'quiet' => ['chat_id' => '-9'],
        'loud' => ['channel' => 'telegram'],
      ],
      \Civi::settings()->get(Router::SETTING),
    );

    // The buffered alert still reaches the chat it was meant for.
    $delivery = json_decode(
      (string) CRM_Core_DAO::singleValueQuery(
        'SELECT delivery FROM civicrm_herald_buffer WHERE id = %1',
        [1 => [$alert, 'Integer']],
      ),
      TRUE,
    );
    $this->assertSame(['chat_id' => '-7', 'channel' => 'telegram'], $delivery);
    $sent = self::sendThroughStub($delivery);
    $this->assertSame(['bot_token' => $bot, 'chat_id' => '-7'], $sent);
  }

  /**
   * @param array<string, mixed> $delivery
   * @return array<string, mixed> The config the Telegram channel got
   */
  private static function sendThroughStub(array $delivery): array {
    $stub = new class() implements ChannelInterface {

      /**
       * @var array<string, mixed>
       */
      public array $config = [];

      public function getKey(): string {
        return 'telegram';
      }

      public function send(string $renderedBody, array $config): void {
        $this->config = $config;
      }

      public function getConfigSchema(): array {
        return (new TelegramChannel())->getConfigSchema();
      }

      public function getSemanticFields(): array {
        return [];
      }

    };
    $registry = new ChannelRegistry();
    $registry->register($stub);
    $channel = (string) $delivery['channel'];
    unset($delivery['channel']);
    (new ChannelSender($registry))->send($channel, 'hi', $delivery);
    ksort($stub->config);
    return $stub->config;
  }

  public function testAFreshInstallGetsOneEmailChannel(): void {
    CRM_Herald_Upgrader_SettingChannels::ensureEmail();
    \Civi\Herald\ChannelFixture::add('email', 'email', ['email_from' => 'h@example.org'], TRUE);
    CRM_Herald_Upgrader_SettingChannels::ensureEmail();

    $this->assertSame(
      ['email' => ['type' => 'email', 'subscribable' => TRUE, 'config' => ['email_from' => 'h@example.org']]],
      self::channels(),
    );
    $options = \Civi\Api4\OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', \Civi\Herald\Channel\PreferredChannelOptions::OPTION_GROUP)
      ->execute()
      ->column('value');
    $this->assertSame(['email'], $options);
  }

}
