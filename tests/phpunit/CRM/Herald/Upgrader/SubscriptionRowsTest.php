<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\ChannelFixture;
use Civi\Herald\LegacyPreferencesFixture;
use Civi\Herald\Subscribers\GroupRecipients;
use Civi\Herald\Subscribers\LegacyPreferences;
use Civi\Herald\Subscribers\SubscribableTopics;
use Civi\Herald\Subscribers\SubscriberLookup;
use Civi\Herald\Subscribers\Subscriptions;
use Civi\Herald\Topic\Router;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * The one preference record per contact becomes a subscription row, and is
 * read as one until then. Not transactional: the old group has a table.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_SubscriptionRowsTest extends TestCase implements HeadlessInterface {

  /**
   * @var list<int>
   */
  private array $contacts = [];

  /**
   * @var array<int, mixed> Domain id => herald_channels
   */
  private array $channels = [];

  private mixed $topics = NULL;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    foreach (CRM_Herald_Upgrader_Domain::ids() as $id) {
      $this->channels[$id] = \Civi::settings($id)->get(ChannelStore::SETTING);
    }
    $this->topics = \Civi::settings()->get(Router::SETTING);
    \Civi::settings()->set(Router::SETTING, ['news' => ['subscribable' => TRUE], 'ops' => ['subscribable' => TRUE]]);
    SubscribableTopics::sync();
    ChannelFixture::add('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))], TRUE);
    ChannelFixture::add('push', 'pushover', ['pushover_app_token' => bin2hex(random_bytes(8))], TRUE);
    LegacyPreferencesFixture::install();
  }

  protected function tearDown(): void {
    foreach ($this->contacts as $id) {
      \Civi\Api4\Contact::delete(checkPermissions: FALSE)->setUseTrash(FALSE)->addWhere('id', '=', $id)->execute();
    }
    foreach ($this->channels as $id => $value) {
      \Civi::settings($id)->set(ChannelStore::SETTING, $value);
    }
    \Civi::settings()->set(Router::SETTING, $this->topics);
    SubscribableTopics::sync();
    LegacyPreferencesFixture::remove();
    CRM_Herald_Upgrader_PreferencesProfile::ensure();
    parent::tearDown();
  }

  /**
   * @param array<string, mixed> $preferences
   */
  private function contact(array $preferences, string $email = ''): int {
    $create = \Civi\Api4\Contact::create(checkPermissions: FALSE)->addValue('contact_type', 'Individual');
    foreach ($preferences as $field => $value) {
      $create->addValue(LegacyPreferences::GROUP . '.' . $field, $value);
    }
    if ($email !== '') {
      $create->addChain('email', \Civi\Api4\Email::create(checkPermissions: FALSE)
        ->addValue('contact_id', '$id')
        ->addValue('email', $email));
    }
    $id = (int) $create->execute()->first()['id'];
    $this->contacts[] = $id;
    return $id;
  }

  /**
   * @return array<int, list<array<string, mixed>>> Contact id => rows
   */
  private function rows(): array {
    $rows = [];
    foreach (\Civi\Api4\CustomValue::get(Subscriptions::GROUP, checkPermissions: FALSE)
      ->addSelect('entity_id', 'channel', 'recipient', 'topics', 'source_entity_type', 'source_entity_id')
      ->addWhere('entity_id', 'IN', $this->contacts)
      ->addOrderBy('id')
      ->execute() as $row) {
      $id = (int) $row['entity_id'];
      unset($row['entity_id'], $row['id']);
      $rows[$id][] = $row;
    }
    return $rows;
  }

  /**
   * @return array<int, array{channel: mixed, recipient: mixed}> Contact id => group channel
   */
  private function groupChannels(): array {
    $prefix = Subscriptions::GROUP_DELIVERY . '.';
    return array_map(
      static fn (array $contact): array => [
        'channel' => $contact[$prefix . 'channel'],
        'recipient' => $contact[$prefix . 'recipient'],
      ],
      \Civi\Api4\Contact::get(checkPermissions: FALSE)
        ->addSelect('id', $prefix . 'channel', $prefix . 'recipient')
        ->addWhere('id', 'IN', $this->contacts)
        ->addWhere('is_deleted', 'IN', [FALSE, TRUE])
        ->addWhere($prefix . 'channel', 'IS NOT EMPTY')
        ->addOrderBy('id')
        ->execute()
        ->indexBy('id')
        ->getArrayCopy(),
    );
  }

  private static function upgrade(): void {
    $ctx = new CRM_Queue_TaskContext();
    $ctx->log = CRM_Core_Error::createDebugLogger();
    self::assertTrue(CRM_Herald_Upgrader::_queueAdapter($ctx, 'herald', 'upgrade_1017'));
  }

  public function testARecordBecomesARowAndTheGroupChannelAndTheOldGroupGoes(): void {
    $telegram = $this->contact([
      'preferred_channel' => 'ops_bot',
      'telegram_chat_id' => '111',
      'pushover_user_key' => 'never read',
      'subscriptions' => ['news', 'ops'],
    ]);
    $groupsOnly = $this->contact(['preferred_channel' => 'email']);
    $noChannel = $this->contact(['subscriptions' => ['news'], 'ntfy_topic' => 'never read']);
    $scoped = $this->contact([
      'preferred_channel' => 'push',
      'pushover_user_key' => 'key',
      'subscriptions' => ['news'],
      'source_entity_type' => 'Event',
      'source_entity_id' => 42,
    ]);

    $counts = CRM_Herald_Upgrader_SubscriptionRows::migrate();
    $this->assertSame(
      ['rows' => 2, 'group_channels' => 3, 'without_channel' => 1, 'unused' => ['pushover_user_key' => 1]],
      $counts,
    );
    self::upgrade();

    $this->assertEquals(
      [
        $telegram => [[
          'channel' => 'ops_bot',
          'recipient' => '111',
          'topics' => ['news', 'ops'],
          'source_entity_type' => NULL,
          'source_entity_id' => NULL,
        ]],
        $scoped => [[
          'channel' => 'push',
          'recipient' => 'key',
          'topics' => ['news'],
          'source_entity_type' => 'Event',
          'source_entity_id' => 42,
        ]],
      ],
      $this->rows(),
    );
    $this->assertSame(
      [
        $telegram => ['channel' => 'ops_bot', 'recipient' => '111'],
        $groupsOnly => ['channel' => 'email', 'recipient' => NULL],
        $scoped => ['channel' => 'push', 'recipient' => 'key'],
      ],
      $this->groupChannels(),
    );
    $this->assertArrayNotHasKey($noChannel, $this->rows());
    $this->assertFalse(LegacyPreferences::pending());
    $this->assertSame([], $this->foreignProfileFields());
  }

  public function testAContactInTheTrashKeepsItsSubscription(): void {
    $trashed = $this->contact([
      'preferred_channel' => 'ops_bot',
      'telegram_chat_id' => '7',
      'subscriptions' => ['news'],
    ]);
    \Civi\Api4\Contact::delete(checkPermissions: FALSE)->addWhere('id', '=', $trashed)->execute();

    $this->assertSame(1, CRM_Herald_Upgrader_SubscriptionRows::migrate()['rows']);
    self::upgrade();

    $this->assertSame('7', $this->rows()[$trashed][0]['recipient']);
    $this->assertSame('7', $this->groupChannels()[$trashed]['recipient']);
  }

  public function testARerunWritesNoRowTwice(): void {
    $this->contact(['preferred_channel' => 'ops_bot', 'telegram_chat_id' => '1', 'subscriptions' => ['news']]);
    $first = CRM_Herald_Upgrader_SubscriptionRows::migrate();
    $this->assertSame([1, 1], [$first['rows'], $first['group_channels']]);
    $again = CRM_Herald_Upgrader_SubscriptionRows::migrate();
    $this->assertSame([0, 0], [$again['rows'], $again['group_channels']]);
    self::upgrade();
    self::upgrade();
    $this->assertCount(1, array_merge(...array_values($this->rows())));
  }

  public function testAChannelOnlyAnotherDomainKnowsKeepsItsRecipient(): void {
    $other = array_values(array_diff(CRM_Herald_Upgrader_Domain::ids(), [CRM_Core_Config::domainID()]));
    if ($other === []) {
      self::markTestSkipped('The test database has a single domain.');
    }
    $errors = (new ChannelStore(NULL, \Civi::settings($other[0])))->create('board_feed', [
      'type' => 'ntfy',
      'label' => 'Board',
      'subscribable' => TRUE,
      'config' => ['ntfy_server' => 'https://ntfy.example.org'],
    ]);
    $this->assertSame([], $errors);
    $id = $this->contact(['preferred_channel' => 'board_feed', 'ntfy_topic' => 'board', 'subscriptions' => ['news']]);

    CRM_Herald_Upgrader_SubscriptionRows::migrate();

    $this->assertSame('board', $this->rows()[$id][0]['recipient']);
  }

  public function testUntilTheUpgradeTheRecordsAreReadAsRows(): void {
    $subscriber = $this->contact([
      'preferred_channel' => 'ops_bot',
      'telegram_chat_id' => '111',
      'subscriptions' => ['news'],
    ]);
    $member = $this->contact(['preferred_channel' => 'email'], 'member@example.org');
    $group = (int) \Civi\Api4\Group::create(checkPermissions: FALSE)
      ->addValue('title', 'herald legacy members')
      ->addChain('member', \Civi\Api4\GroupContact::create(checkPermissions: FALSE)
        ->addValue('group_id', '$id')
        ->addValue('contact_id', $member))
      ->execute()
      ->first()['id'];

    $this->assertSame(
      [['contact_id' => $subscriber, 'channel' => 'ops_bot', 'config' => ['chat_id' => '111']]],
      (new SubscriberLookup())->findByTopic('news'),
    );
    $this->assertSame(1, Subscriptions::subscribers('news'));
    $this->assertSame(
      [['contact_id' => $member, 'channel' => 'email', 'config' => ['email_to' => 'member@example.org']]],
      (new GroupRecipients())->findByGroup($group),
    );
    \Civi\Api4\Group::delete(checkPermissions: FALSE)->addWhere('id', '=', $group)->execute();
  }

  /**
   * Profile fields naming a custom field that no longer exists.
   *
   * @return list<string>
   */
  private function foreignProfileFields(): array {
    $fields = \Civi\Api4\UFField::get(checkPermissions: FALSE)
      ->addSelect('field_name')
      ->addWhere('field_name', 'LIKE', 'custom\_%')
      ->execute()
      ->column('field_name');
    $existing = array_map(
      static fn (mixed $id): string => 'custom_' . $id,
      \Civi\Api4\CustomField::get(checkPermissions: FALSE)->addSelect('id')->execute()->column('id'),
    );
    return array_values(array_diff($fields, $existing));
  }

}
