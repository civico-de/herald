<?php

declare(strict_types = 1);

use Civi\Herald\Topic\Router;

/**
 * The upgrade step run again after it failed half-way.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_TopicSubscriptionsRerunTest extends CRM_Herald_Upgrader_TopicSubscriptionsTestCase {

  public function testARerunAfterAFailedStepStillOffersTheNamedTopics(): void {
    self::oldTypes();
    foreach (CRM_Herald_Upgrader_Domain::ids() as $id) {
      \Civi::settings($id)->set(Router::SETTING, []);
    }
    \Civi::settings()->set(Router::SETTING, [
      'default' => ['channel' => 'email'],
      'alerts' => ['delivery_mode' => 'subscribers', 'subscription_type' => 'alerts'],
    ]);
    $action = $this->oldDeliveries();
    // The step failed once its deliveries were rewritten.
    $key = new CRM_Herald_Upgrader_SubscriberKey();
    $domains = [new CRM_Herald_Upgrader_Domain(CRM_Core_Config::domainID(), '')];
    CRM_Herald_Upgrader_StoredDeliveries::rewrite($domains, $key, $key, new CRM_Herald_Upgrader_SubscriberRoutes($key));

    CRM_Herald_Upgrader_TopicSubscriptions::migrate();

    $this->assertDeliveriesNameTheirTopic($action);
    $map = \Civi::settings()->get(Router::SETTING);
    $this->assertSame(['delivery_mode' => 'subscribers', 'subscribable' => TRUE], $map['alerts']);
    $this->assertSame(['subscribable' => TRUE, 'label' => 'Members news'], $map['members_news']);
    $this->assertSame(['subscribable' => TRUE, 'label' => 'Daily digest'], $map['daily_digest']);
  }

}
