<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Api4\Afform;
use Civi\Herald\ChannelFixture;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The tab and the self-service form save the group channel and the rows in
 * one submit, refuse an entry its channel cannot deliver to, and stand in for
 * core's tabs of both groups.
 *
 * @group headless
 */
class NotificationsFormTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private const TYPES = ['ops_bot' => 'telegram', 'email' => 'email'];

  private int $contact = 0;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    ChannelFixture::add('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))], TRUE);
    \Civi::settings()->set(\Civi\Herald\Topic\Router::SETTING, ['news' => ['subscribable' => TRUE]]);
    SubscribableTopics::sync();
    /** @var array{id: int} $contact */
    $contact = \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Ada')
      ->execute()
      ->single();
    $this->contact = $contact['id'];
  }

  /**
   * @param array<string, mixed> $group Group channel field => value
   * @param list<array<string, mixed>> $rows
   * @return array<string, mixed>
   */
  private static function record(array $group, array $rows): array {
    $fields = [];
    foreach ($group as $name => $value) {
      $fields[Subscriptions::GROUP_DELIVERY . '.' . $name] = $value;
    }
    return ['fields' => $fields, 'joins' => ['Custom_' . Subscriptions::GROUP => $rows]];
  }

  /**
   * @param array<string, mixed> $record
   */
  private function submit(string $form, array $record, array $args = []): void {
    Afform::submit(checkPermissions: FALSE)
      ->setName($form)
      ->setArgs($args)
      ->setValues(['Contact1' => [$record]])
      ->execute();
  }

  /**
   * @return array{group: array<string, mixed>, rows: list<array<string, mixed>>}
   */
  private function saved(): array {
    /** @var array<string, mixed> $contact */
    $contact = \Civi\Api4\Contact::get(checkPermissions: FALSE)
      ->addSelect(Subscriptions::GROUP_DELIVERY . '.channel', Subscriptions::GROUP_DELIVERY . '.recipient')
      ->addWhere('id', '=', $this->contact)
      ->execute()
      ->single();
    unset($contact['id']);
    $rows = \Civi\Api4\CustomValue::get(Subscriptions::GROUP, FALSE)
      ->addSelect('channel', 'recipient', 'topics')
      ->addWhere('entity_id', '=', $this->contact)
      ->addOrderBy('id')
      ->execute()
      ->getArrayCopy();
    return [
      'group' => $contact,
      'rows' => array_map(static fn (array $row): array => array_diff_key($row, ['id' => 1]), $rows),
    ];
  }

  public function testAHealthyEntryRaisesNothing(): void {
    $this->assertSame([], NotificationsRules::problems(self::record(
      ['channel' => 'email', 'recipient' => ''],
      [['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news']]],
    ), self::TYPES));
    $this->assertSame([], NotificationsRules::problems(self::record([], []), self::TYPES));
  }

  public function testEachProblemNamesWhereItIs(): void {
    $this->assertSame(
      [
        'Messages to groups: This channel needs a recipient.',
        'Subscription 2: Pick at least one topic.',
        'Subscription 2: Pick the entity type of the record, or clear the ID.',
      ],
      NotificationsRules::problems(self::record(
        ['channel' => 'ops_bot', 'recipient' => ' '],
        [
          ['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news']],
          [
            'channel' => 'email',
            'recipient' => '',
            'topics' => [],
            'source_entity_type' => '',
            'source_entity_id' => 42,
          ],
        ],
      ), self::TYPES),
    );
  }

  public function testTheTabSavesTheGroupChannelAndTheRowsAndDropsARemovedRow(): void {
    $args = ['contact_id' => $this->contact];
    $this->submit(
      'afformHeraldNotifications',
      self::record(
        ['channel' => 'ops_bot', 'recipient' => '222'],
        [
          ['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news']],
          ['channel' => 'email', 'recipient' => '', 'topics' => ['news']],
        ],
      ),
      $args,
    );
    $this->assertEquals(
      [
        'group' => [
          Subscriptions::GROUP_DELIVERY . '.channel' => 'ops_bot',
          Subscriptions::GROUP_DELIVERY . '.recipient' => '222',
        ],
        'rows' => [
          ['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news']],
          ['channel' => 'email', 'recipient' => NULL, 'topics' => ['news']],
        ],
      ],
      $this->saved(),
    );

    $this->submit(
      'afformHeraldNotifications',
      self::record(
        ['channel' => 'ops_bot', 'recipient' => '222'],
        [['channel' => 'email', 'recipient' => '', 'topics' => ['news']]],
      ),
      $args,
    );
    $this->assertEquals(
      [['channel' => 'email', 'recipient' => NULL, 'topics' => ['news']]],
      $this->saved()['rows'],
    );
  }

  public function testABadEntryIsRefusedAndNothingIsSaved(): void {
    try {
      $this->submit(
        'afformHeraldNotifications',
        self::record(
          ['channel' => 'email', 'recipient' => ''],
          [['channel' => 'ops_bot', 'recipient' => '', 'topics' => ['news']]],
        ),
        ['contact_id' => $this->contact],
      );
      $this->fail('The form saved a row without a recipient.');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('Subscription 1: This channel needs a recipient.', $e->getMessage());
    }
    $this->assertSame([], $this->saved()['rows']);
  }

  public function testTheSelfServiceFormSavesForTheLoggedInContactOnly(): void {
    \CRM_Core_Session::singleton()->set('userID', $this->contact);
    $this->submit(
      'afformHeraldMyNotifications',
      self::record(
        ['channel' => 'email', 'recipient' => ''],
        [['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news']]],
      ),
      ['contact_id' => $this->contact + 1000],
    );
    $this->assertEquals(
      [['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news']]],
      $this->saved()['rows'],
    );
  }

  public function testRemovingARowOnTheSelfServiceFormKeepsTheOtherRowsScope(): void {
    \Civi\Api4\CustomValue::save(Subscriptions::GROUP, FALSE)
      ->setRecords([
        [
          'entity_id' => $this->contact,
          'channel' => 'email',
          'topics' => ['news'],
          'source_entity_type' => 'Event',
          'source_entity_id' => 1,
        ],
        ['entity_id' => $this->contact, 'channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news']],
      ])
      ->execute();
    \CRM_Core_Session::singleton()->set('userID', $this->contact);
    $prefill = Afform::prefill(checkPermissions: FALSE)->setName('afformHeraldMyNotifications')->execute()->first();
    $rows = (array) ($prefill['values'][0]['joins']['Custom_' . Subscriptions::GROUP] ?? []);
    $this->assertCount(2, $rows);

    $this->submit('afformHeraldMyNotifications', self::record([], [$rows[1]]));

    $left = \Civi\Api4\CustomValue::get(Subscriptions::GROUP, FALSE)
      ->addSelect('channel', 'source_entity_type')
      ->addWhere('entity_id', '=', $this->contact)
      ->execute()
      ->getArrayCopy();
    $this->assertSame(
      [['channel' => 'ops_bot', 'source_entity_type' => NULL]],
      array_map(static fn (array $row): array => array_diff_key($row, ['id' => 1]), $left),
    );
  }

  public function testTheFormStandsInForCoreTabsOfBothGroups(): void {
    $native = array_map(
      static fn (string $name): string => (
        'custom_' . (int) \CRM_Core_BAO_CustomGroup::getGroup(['name' => $name])['id']
      ),
      [Subscriptions::GROUP, Subscriptions::GROUP_DELIVERY],
    );
    $tabs = [
      ['id' => 'summary'],
      ['id' => $native[0]],
      ['id' => $native[1]],
      'herald_notifications' => ['id' => 'herald_notifications'],
    ];
    $form = \Civi::service('herald.notifications_form');
    $form->hook_civicrm_tabset('civicrm/contact/view', $tabs, ['contact_id' => $this->contact]);
    $this->assertSame(['summary', 'herald_notifications'], array_values(array_column($tabs, 'id')));

    $event = [['id' => $native[0]]];
    $form->hook_civicrm_tabset('civicrm/event/manage', $event, []);
    $this->assertSame([['id' => $native[0]]], $event);
  }

}
