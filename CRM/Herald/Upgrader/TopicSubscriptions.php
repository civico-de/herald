<?php

declare(strict_types = 1);

use Civi\Herald\Subscribers\SubscribableTopics;

/**
 * Subscription types become subscribable topics of the same name, so the
 * stored subscriptions stay valid, and every delivery names the topic whose
 * subscribers it reaches.
 */
final class CRM_Herald_Upgrader_TopicSubscriptions {

  private const GROUP_TITLE = 'Herald: Subscribable topic';

  /**
   * @return array{topics: int, deliveries: int}
   */
  public static function migrate(): array {
    $domains = array_map(
      static fn (int $id): CRM_Herald_Upgrader_Domain => new CRM_Herald_Upgrader_Domain($id, ''),
      CRM_Herald_Upgrader_Domain::ids(),
    );
    $key = new CRM_Herald_Upgrader_SubscriberKey();
    $deliveries = CRM_Herald_Upgrader_StoredDeliveries::rewrite(
      $domains,
      $key,
      $key,
      new CRM_Herald_Upgrader_SubscriberRoutes($key),
    );
    $labels = CRM_Herald_Upgrader_SubscriptionTypes::labels($key->named);
    // Writing the topics fills the group.
    CRM_Herald_Upgrader_OptionField::ensureGroup(SubscribableTopics::OPTION_GROUP, self::GROUP_TITLE);
    $topics = 0;
    foreach ($domains as $domain) {
      $topics += CRM_Herald_Upgrader_OfferedTopics::offer($domain, $labels);
    }
    CRM_Herald_Upgrader_SubscriptionTypes::retire();
    CRM_Herald_Upgrader_OptionField::move(
      'subscriptions',
      SubscribableTopics::OPTION_GROUP,
      self::GROUP_TITLE,
      SubscribableTopics::sync(...),
    );
    return ['topics' => $topics, 'deliveries' => $deliveries];
  }

}
