<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Api4\Herald;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Channel\PreferredChannelOptions;
use Civi\Herald\Topic\Router;

/**
 * Herald.saveChannel / getChannels / deleteChannel: the channels screen's
 * reads and writes, and how secrets are kept.
 *
 * @group headless
 */
class ChannelsTest extends AdminTestCase {

  protected function setUp(): void {
    parent::setUp();
    \Civi::service('crypto.registry')->addSymmetricKey([
      'key' => random_bytes(32),
      'suite' => 'aes-cbc',
      'tags' => ['CRED'],
      'weight' => -1,
    ]);
  }

  /**
   * @param array<string, mixed> $config
   * @return list<array<string, mixed>>
   */
  private static function save(
    string $name,
    string $type,
    array $config,
    bool $isNew = TRUE,
    array $extra = [],
  ): array {
    return self::call('saveChannel', [
      'name' => $name,
      'isNew' => $isNew,
      'channel' => ['type' => $type, 'label' => 'Ops bot', 'config' => $config] + $extra,
    ]);
  }

  private static function stored(string $name): array {
    $all = \Civi::settings()->get(ChannelStore::SETTING);
    return $all[$name]['config'];
  }

  public function testAFreshInstallHasTheEmailChannel(): void {
    $rows = array_column(self::call('getChannels'), NULL, 'name');
    $this->assertSame('email', $rows['email']['type']);
    self::save('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))]);
    $this->assertSame(['email' => $rows['email']['label']], PreferredChannelOptions::emailChannels());
  }

  public function testSecretsAreStoredEncryptedAndNeverRead(): void {
    $secret = bin2hex(random_bytes(8));
    $rows = self::save('ops_bot', 'telegram', ['bot_token' => $secret, 'chat_id' => '-100']);

    $this->assertSame(['bot_token' => ['set' => TRUE], 'chat_id' => '-100'], $rows[0]['config']);
    $stored = self::stored('ops_bot');
    $this->assertStringNotContainsString($secret, $stored['bot_token']);
    $this->assertSame('-100', $stored['chat_id']);
    $this->assertSame($secret, (new ChannelStore())->get('ops_bot')['config']['bot_token']);
    $listed = array_column(self::call('getChannels'), NULL, 'name')['ops_bot'];
    $this->assertSame(['set' => TRUE], $listed['config']['bot_token']);
  }

  public function testHeadersRoundTripAsAnObject(): void {
    self::save('hook', 'webhook', [
      'webhook_url' => 'https://hooks.example.org/a',
      'webhook_headers' => ['X-Key' => 'k'],
    ]);
    $this->assertSame(['X-Key' => 'k'], (new ChannelStore())->get('hook')['config']['webhook_headers']);
  }

  public function testSendingBackTheMaskKeepsTheStoredSecret(): void {
    $headers = ['X-Key' => bin2hex(random_bytes(8))];
    $row = self::save('hook', 'webhook', [
      'webhook_url' => 'https://hooks.example.org/a',
      'webhook_headers' => $headers,
    ])[0];

    self::save('hook', 'webhook', $row['config'], FALSE);

    $this->assertSame($headers, (new ChannelStore())->get('hook')['config']['webhook_headers']);
  }

  public function testAChannelOfAnUnknownTypeShowsNoValue(): void {
    $token = bin2hex(random_bytes(8));
    \Civi::settings()->set(ChannelStore::SETTING, [
      'typo' => ['type' => 'telegrm', 'label' => 'Typo', 'config' => ['bot_token' => $token]],
    ]);
    $rows = array_column((new ChannelStore())->masked(), NULL, 'name');
    $this->assertSame(['bot_token' => ['set' => TRUE]], $rows['typo']['config']);
  }

  public function testAnEmptySecretKeepsTheStoredOneAndNullClearsIt(): void {
    $appToken = bin2hex(random_bytes(8));
    self::save('phones', 'pushover', ['pushover_app_token' => $appToken, 'pushover_user_key' => 'u1']);
    self::save('phones', 'pushover', ['pushover_app_token' => '', 'pushover_user_key' => 'u2'], FALSE);
    $this->assertSame(
      ['pushover_user_key' => 'u2', 'pushover_app_token' => $appToken],
      (new ChannelStore())->get('phones')['config'],
    );

    self::save('alerts', 'ntfy', ['ntfy_token' => bin2hex(random_bytes(8)), 'ntfy_topic' => 't']);
    self::save('alerts', 'ntfy', ['ntfy_token' => NULL, 'ntfy_topic' => 't'], FALSE);
    $this->assertSame(['ntfy_topic' => 't'], (new ChannelStore())->get('alerts')['config']);
  }

  public function testInvalidChannelsAreRejected(): void {
    $this->assertRejected(
      'saveChannel',
      ['name' => 'Ops Bot', 'isNew' => TRUE, 'channel' => ['type' => 'telegram', 'config' => ['chat' => '1']]],
      [
        'A channel name starts with a lowercase letter',
        'The channel type "telegram" has no key "chat".',
        '"Bot token" is required.',
      ],
    );
    $this->assertRejected(
      'saveChannel',
      ['name' => 'board', 'isNew' => TRUE, 'channel' => ['type' => 'activity', 'subscribable' => TRUE]],
      ['Contacts cannot receive through a channel of type "activity".'],
    );
    $this->assertRejected(
      'saveChannel',
      ['name' => 'pigeon', 'isNew' => TRUE, 'channel' => ['type' => 'carrier_pigeon']],
      ['The channel type "carrier_pigeon" is not registered.'],
    );
    $this->assertRejected(
      'saveChannel',
      ['name' => 'email', 'isNew' => TRUE, 'channel' => ['type' => 'email']],
      ['A channel named "email" already exists.'],
    );
    $this->assertRejected(
      'saveChannel',
      ['name' => 'email', 'isNew' => FALSE, 'channel' => ['type' => 'webhook']],
      ['The type of a channel cannot change'],
    );
    $this->assertRejected(
      'saveChannel',
      ['name' => 'gone', 'isNew' => FALSE, 'channel' => ['type' => 'email']],
      ['The channel "gone" no longer exists.'],
    );
    $this->assertRejected(
      'saveChannel',
      [
        'name' => 'hook',
        'isNew' => TRUE,
        'channel' => ['type' => 'webhook', 'config' => ['webhook_auth_token' => bin2hex(random_bytes(8))]],
      ],
      ['"Bearer token (Authorization header)" is sent only to the channel\'s "URL"; set "URL" as well.'],
    );
  }

  public function testAHealthyChannelOfEveryTypeSaves(): void {
    self::save('t', 'telegram', ['bot_token' => bin2hex(random_bytes(8))]);
    self::save('w', 'webhook', []);
    self::save('n', 'ntfy', []);
    self::save('p', 'pushover', ['pushover_app_token' => bin2hex(random_bytes(8))]);
    self::save('e', 'email', ['email_from' => 'Herald <h@example.org>']);
    self::save('a', 'activity', ['activity_assignee_contact_id' => 1]);
    $this->assertCount(7, self::call('getChannels'));
  }

  public function testSubscribableChannelsBecomeThePreferredChannelOptions(): void {
    self::save('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))], TRUE, ['subscribable' => TRUE]);
    self::save('quiet_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))], TRUE, [
      'subscribable' => TRUE,
      'active' => FALSE,
    ]);
    $options = static fn (): array => \Civi\Api4\OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', PreferredChannelOptions::OPTION_GROUP)
      ->execute()
      ->column('label', 'value');

    $this->assertSame('Ops bot', $options()['ops_bot']);
    $this->assertArrayNotHasKey('quiet_bot', $options());
    self::call('deleteChannel', ['name' => 'ops_bot']);
    $this->assertArrayNotHasKey('ops_bot', $options());
  }

  public function testChannelsOfEveryDomainBecomeOptions(): void {
    $other = array_values(array_diff(
      \Civi\Api4\Domain::get(FALSE)->addSelect('id')->execute()->column('id'),
      [\CRM_Core_Config::domainID()],
    ));
    if ($other === []) {
      self::markTestSkipped('The test database has a single domain.');
    }
    $record = static fn (string $label): array => [
      'type' => 'ntfy',
      'label' => $label,
      'subscribable' => TRUE,
      'config' => [],
    ];
    $there = new ChannelStore(NULL, \Civi::settings((int) $other[0]));
    $this->assertSame([], $there->create('team_feed', $record('Team feed')));
    $this->assertSame([], $there->create('ops_feed', $record('Their ops feed')));
    $this->assertSame([], (new ChannelStore())->create('ops_feed', $record('Ops feed')));

    PreferredChannelOptions::sync();

    $options = \Civi\Api4\OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', PreferredChannelOptions::OPTION_GROUP)
      ->execute()
      ->column('label', 'value');
    $this->assertSame('Team feed', $options['team_feed']);
    $this->assertSame('Ops feed', $options['ops_feed']);
  }

  public function testAChannelInUseIsNotDeleted(): void {
    self::save('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))]);
    \Civi::settings()->set(Router::SETTING, ['ops' => ['channel' => 'ops_bot']]);

    $this->assertRejected(
      'deleteChannel',
      ['name' => 'ops_bot'],
      ['The channel "ops_bot" is still in use:', 'Topic "ops"'],
    );
    $this->assertRejected(
      'deleteChannel',
      ['name' => 'email'],
      ['The channel "email" is still in use:', 'contact email channel'],
    );
    $this->assertRejected('deleteChannel', ['name' => 'nope'], ['The channel "nope" does not exist.']);
    $usage = array_column(self::call('getChannels', ['withUsage' => TRUE]), 'used_by', 'name');
    $this->assertSame(['Topic "ops"'], $usage['ops_bot']);
  }

  public function testRuleActionsAndJobsCountAsUsers(): void {
    self::save('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))]);
    \Civi::settings()->set(Router::SETTING, ['mornings' => ['channel' => 'email']]);
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule (name, label, trigger_id, is_active, created_date)
       VALUES ('herald_test_users', 'Donations', (SELECT MIN(id) FROM civirule_trigger), 1, NOW())",
    );
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule_action (rule_id, action_id, action_params)
       SELECT LAST_INSERT_ID(), id, %1 FROM civirule_action WHERE class_name = 'CRM_Herald_CivirulesAdapter_Action_Digest'",
      [1 => [serialize(['digest' => 'd', 'channel' => 'ops_bot']), 'String']],
    );
    \Civi\Api4\Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'Morning digest',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode(['version' => 4, 'actionParams' => ['digest' => 'd', 'topic' => 'mornings']]),
      ])
      ->execute();

    $this->assertSame(['Rule "Donations"'], \Civi\Herald\Admin\ParamsInUse::ofChannel('ops_bot'));
    $this->assertSame(['Scheduled job "Morning digest"'], \Civi\Herald\Admin\ParamsInUse::ofTopic('mornings'));
  }

  public function testRulesOnATopicWithoutARouteUseTheDefaultRoute(): void {
    \Civi::settings()->set(Router::SETTING, ['default' => ['channel' => 'email'], 'ops' => ['channel' => 'email']]);
    \Civi::settings()->set('herald_monitor_topic', 'ops');
    foreach (['Unrouted' => 'mornings', 'Routed' => 'ops'] as $name => $topic) {
      \Civi\Api4\Job::create(checkPermissions: FALSE)
        ->setValues([
          'name' => $name,
          'run_frequency' => 'Daily',
          'api_entity' => 'Herald',
          'api_action' => 'notifyDigest',
          'parameters' => json_encode(['version' => 4, 'actionParams' => ['digest' => 'd', 'topic' => $topic]]),
        ])
        ->execute();
    }

    $this->assertSame(['Scheduled job "Unrouted"'], \Civi\Herald\Admin\ParamsInUse::ofTopic('default'));
    $this->assertSame(['Scheduled job "Routed"', 'The monitor'], \Civi\Herald\Admin\ParamsInUse::ofTopic('ops'));
  }

  public function testBufferedAndParkedAlertsCountAsUsers(): void {
    self::save('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))]);
    foreach (['NULL', 'NOW()'] as $parkedAt) {
      \CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_herald_buffer (group_hash, window_minutes, body, delivery, created_date, parked_at)
         VALUES ('h', 5, 'Hi', %1, NOW(), {$parkedAt})",
        [1 => [json_encode(['channel' => 'ops_bot', 'chat_id' => '-7']), 'String']],
      );
    }

    $this->assertSame(['2 buffered or parked alerts'], \Civi\Herald\Admin\ParamsInUse::ofChannel('ops_bot'));
    $this->assertSame([], \Civi\Herald\Admin\ParamsInUse::ofChannel('email_other'));
  }

  public function testAnInactiveChannelDoesNotSend(): void {
    self::save('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))], TRUE, ['active' => FALSE]);
    $this->expectExceptionMessage('herald: the channel "ops_bot" is switched off.');
    (new ChannelStore())->get('ops_bot');
  }

  public function testOnlyAdministratorsMayWrite(): void {
    $this->assertAdminOnly(static fn () => Herald::saveChannel()->setName('x')->execute());
    $this->assertAdminOnly(static fn () => Herald::deleteChannel()->setName('email')->execute());
    $this->assertAdminOnly(static fn () => Herald::getChannels()->execute());
  }

}
