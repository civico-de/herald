<?php

declare(strict_types = 1);

use Civi\Herald\Topic\Router;

/**
 * Subscription types become subscribable topics.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_TopicSubscriptionsTest extends CRM_Herald_Upgrader_TopicSubscriptionsTestCase {

  public function testSubscriptionTypesBecomeTopicsAndDeliveriesNameThem(): void {
    self::oldTypes();
    // Caches cleared between the code swap and the upgrade keep the types.
    CRM_Core_ManagedEntities::singleton(fresh: TRUE)->reconcile(['herald']);
    CRM_Core_ManagedEntities::singleton(fresh: TRUE)->reconcile(['herald']);
    $this->assertSame(
      'Members news',
      \Civi\Api4\OptionValue::get(checkPermissions: FALSE)
        ->addWhere('option_group_id:name', '=', 'herald_subscription_type')
        ->addWhere('value', '=', 'members_news')
        ->execute()
        ->first()['label'] ?? NULL,
    );
    $this->assertSame(
      1,
      \Civi\Api4\OptionValue::get(checkPermissions: FALSE)
        ->addWhere('option_group_id:name', '=', 'herald_subscription_type')
        ->addWhere('value', '=', 'completed_alert')
        ->execute()
        ->count(),
      'contacts keep a shipped type on their forms until the upgrade',
    );
    // A shipped type keeps its subscribers although the reconcile removed its option.
    $this->subscriber(['members_news', 'completed_alert']);
    $others = array_diff(CRM_Herald_Upgrader_Domain::ids(), [CRM_Core_Config::domainID()]);
    foreach ($others as $id) {
      \Civi::settings($id)->set(Router::SETTING, []);
    }
    \Civi::settings()->set(Router::SETTING, [
      'default' => ['channel' => 'email'],
      'members' => ['delivery_mode' => 'subscribers', 'subscription_type' => 'members_news'],
      'completed_alert' => ['delivery_mode' => 'subscribers', 'subscription_type' => 'completed_alert'],
    ]);
    $action = $this->oldDeliveries();

    $moved = CRM_Herald_Upgrader_TopicSubscriptions::migrate();

    // completed_alert has a route here; the other domains get all three.
    $this->assertSame(['topics' => 2 + (3 * count($others)), 'deliveries' => 6], $moved);
    $this->assertSame(
      [
        'default' => ['channel' => 'email'],
        'members' => ['delivery_mode' => 'subscribers', 'subscribers_of' => 'members_news'],
        'completed_alert' => [
          'delivery_mode' => 'subscribers',
          'subscribable' => TRUE,
          'label' => 'Every completed contribution',
        ],
        'members_news' => ['subscribable' => TRUE, 'label' => 'Members news'],
        'daily_digest' => ['subscribable' => TRUE, 'label' => 'Daily digest'],
      ],
      \Civi::settings()->get(Router::SETTING),
    );
    $this->assertDeliveriesNameTheirTopic($action);
    $this->assertSame(
      ['delivery_mode' => 'direct', 'channel' => 'email'],
      json_decode(
        (string) CRM_Core_DAO::singleValueQuery(
          "SELECT delivery FROM civicrm_herald_buffer WHERE group_hash = 'herald_test_stale'",
        ),
        TRUE,
      ),
    );
    $this->assertTheSubscriptionsStay();

    $map = \Civi::settings()->get(Router::SETTING);
    $this->assertSame(['topics' => 0, 'deliveries' => 0], CRM_Herald_Upgrader_TopicSubscriptions::migrate());
    $this->assertSame($map, \Civi::settings()->get(Router::SETTING));
  }

  public function testEveryDomainGetsTheTopicsAndKeepsItsRoutes(): void {
    $other = array_values(array_diff(CRM_Herald_Upgrader_Domain::ids(), [CRM_Core_Config::domainID()]));
    if ($other === []) {
      self::markTestSkipped('The test database has a single domain.');
    }
    \Civi::settings()->set(Router::SETTING, [
      'default' => ['channel' => 'email'],
      'digest' => ['delivery_mode' => 'subscribers', 'subscription_type' => 'alerts'],
    ]);
    \Civi::settings($other[0])->set(Router::SETTING, [
      'default' => ['delivery_mode' => 'subscribers', 'subscription_type' => 'members'],
      'board' => ['subscription_type' => 'board'],
      'alerts' => ['aggregate_minutes' => 5],
    ]);

    CRM_Herald_Upgrader_TopicSubscriptions::migrate();

    // "default" names other subscribers, so the route keeps naming its own,
    // and a topic that reached those of "default" keeps reaching them.
    $this->assertSame(
      [
        'default' => ['delivery_mode' => 'subscribers', 'subscribers_of' => 'members'],
        'board' => ['subscribers_of' => 'board', 'subscribable' => TRUE],
        'alerts' => ['aggregate_minutes' => 5, 'subscribers_of' => 'members', 'subscribable' => TRUE],
        'members' => ['subscribable' => TRUE],
      ],
      \Civi::settings($other[0])->get(Router::SETTING),
    );
    $this->assertSame(
      [
        'default' => ['channel' => 'email'],
        'digest' => ['delivery_mode' => 'subscribers', 'subscribers_of' => 'alerts'],
        'alerts' => ['subscribable' => TRUE],
        'members' => ['subscribable' => TRUE],
        'board' => ['subscribable' => TRUE],
      ],
      \Civi::settings()->get(Router::SETTING),
    );
  }

  public function testDefaultPassesItsSubscriptionTypeOnWithoutBeingOffered(): void {
    foreach (array_diff(CRM_Herald_Upgrader_Domain::ids(), [CRM_Core_Config::domainID()]) as $id) {
      \Civi::settings($id)->set(Router::SETTING, []);
    }
    \Civi::settings()->set(Router::SETTING, [
      'default' => ['channel' => 'email', 'subscription_type' => 'news'],
      'alerts' => ['delivery_mode' => 'subscribers'],
    ]);
    CRM_Herald_Upgrader_TopicSubscriptions::migrate();
    $map = \Civi::settings()->get(Router::SETTING);
    $this->assertSame(['channel' => 'email', 'subscribers_of' => 'news'], $map['default']);
    $this->assertSame(['subscribable' => TRUE], $map['news']);
    $this->assertSame('news', Router::resolveFrom($map, 'alerts')['subscribers_of']);

    \Civi::settings()->set(Router::SETTING, ['default' => ['channel' => 'email', 'subscription_type' => 'news']]);
    CRM_Herald_Upgrader_TopicSubscriptions::migrate();
    $this->assertSame(['default' => ['channel' => 'email']], \Civi::settings()->get(Router::SETTING));

    \Civi::settings()->set(Router::SETTING, ['default' => ['delivery_mode' => 'subscribers']]);
    CRM_Herald_Upgrader_TopicSubscriptions::migrate();
    $this->assertSame(['default' => ['delivery_mode' => 'subscribers']], \Civi::settings()->get(Router::SETTING));
  }

}
