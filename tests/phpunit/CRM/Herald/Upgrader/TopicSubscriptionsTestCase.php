<?php

declare(strict_types = 1);

use Civi\Herald\Subscribers\SubscribableTopics;
use Civi\Herald\Topic\Router;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Subscription types of an older release and the deliveries naming them. Not
 * transactional: the subscriptions field moves between option groups.
 */
abstract class CRM_Herald_Upgrader_TopicSubscriptionsTestCase extends TestCase implements HeadlessInterface {

  /**
   * @var array<int, mixed> Domain id => herald_topics
   */
  protected array $saved = [];

  /**
   * @var list<int>
   */
  protected array $contacts = [];

  protected ?int $job = NULL;

  protected ?int $rule = NULL;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    foreach (CRM_Herald_Upgrader_Domain::ids() as $id) {
      $this->saved[$id] = \Civi::settings($id)->get(Router::SETTING);
    }
  }

  protected function tearDown(): void {
    foreach ($this->saved as $id => $value) {
      \Civi::settings($id)->set(Router::SETTING, $value);
    }
    foreach ($this->contacts as $id) {
      \Civi\Api4\Contact::delete(checkPermissions: FALSE)->setUseTrash(FALSE)->addWhere('id', '=', $id)->execute();
    }
    if ($this->job !== NULL) {
      \Civi\Api4\Job::delete(checkPermissions: FALSE)->addWhere('id', '=', $this->job)->execute();
    }
    if ($this->rule !== NULL) {
      CRM_Core_DAO::executeQuery('DELETE FROM civirule_rule WHERE id = %1', [1 => [$this->rule, 'Integer']]);
    }
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_herald_buffer WHERE group_hash LIKE 'herald_test_%'");
    CRM_Herald_Upgrader_OptionField::repoint(
      'subscriptions',
      SubscribableTopics::OPTION_GROUP,
      'Herald: Subscribable topic',
    );
    \Civi\Api4\OptionGroup::delete(checkPermissions: FALSE)
      ->addWhere('name', '=', 'herald_subscription_type')
      ->execute();
    SubscribableTopics::sync();
    parent::tearDown();
  }

  /**
   * The old option group with an admin's own type and a shipped one nobody
   * uses, and the field on it as an older release declared it.
   */
  protected static function oldTypes(): void {
    $group = \Civi\Api4\OptionGroup::create(checkPermissions: FALSE)
      ->addValue('name', 'herald_subscription_type')
      ->addValue('title', 'Herald: Subscription type')
      ->execute()
      ->first()['id'];
    foreach (['members_news' => 'Members news', 'weekly_digest' => 'Weekly digest'] as $name => $label) {
      \Civi\Api4\OptionValue::create(checkPermissions: FALSE)
        ->addValue('option_group_id', $group)
        ->addValue('name', $name)
        ->addValue('value', $name)
        ->addValue('label', $label)
        ->execute();
    }
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_custom_field SET option_group_id = %1 WHERE name = 'subscriptions'",
      [1 => [$group, 'Integer']],
    );
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_managed SET checksum = NULL, entity_modified_date = NULL
       WHERE module = 'herald' AND name = 'CustomField_herald_preferences_subscriptions'",
    );
  }

  /**
   * @param list<string> $subscriptions
   */
  protected function subscriber(array $subscriptions): void {
    $this->contacts[] = (int) \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('herald_preferences.subscriptions', $subscriptions)
      ->execute()
      ->first()['id'];
  }

  /**
   * @param array<string, mixed> $params
   */
  protected function ruleAction(array $params): int {
    $handler = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civirule_action WHERE class_name = 'CRM_Herald_CivirulesAdapter_Action_Notification'",
    );
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule (name, label, trigger_id, is_active, created_date)
       VALUES ('herald_test_topics', 'Topics', (SELECT MIN(id) FROM civirule_trigger), 1, NOW())",
    );
    $this->rule = (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_action (rule_id, action_id, action_params) VALUES (%1, %2, %3)',
      [1 => [$this->rule, 'Integer'], 2 => [$handler, 'Integer'], 3 => [serialize($params), 'String']],
    );
    return (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  /**
   * A rule action, a job and a buffered alert to subscribers of a type.
   *
   * @return int The rule action
   */
  protected function oldDeliveries(): int {
    // Since named channels, FALSE overrides a channel's TRUE.
    $action = $this->ruleAction([
      'delivery_mode' => 'subscribers',
      'subscription_type' => 'members_news',
      'disable_notification' => FALSE,
    ]);
    $this->job = (int) \Civi\Api4\Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald test topics',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode([
          'version' => 4,
          'actionParams' => ['digest' => 'd', 'delivery_mode' => 'subscribers', 'subscription_type' => 'daily_digest'],
        ]),
      ])
      ->execute()
      ->first()['id'];
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_herald_buffer (group_hash, window_minutes, body, delivery, created_date)
       VALUES ('herald_test_topics', 5, 'Hi', %1, NOW())",
      [1 => [json_encode(['delivery_mode' => 'subscribers', 'subscription_type' => 'members_news']), 'String']],
    );
    // Left over from when it went to subscribers; never read since.
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_herald_buffer (group_hash, window_minutes, body, delivery, created_date)
       VALUES ('herald_test_stale', 5, 'Hi', %1, NOW())",
      [1 => [
        json_encode(['delivery_mode' => 'direct', 'channel' => 'email', 'subscription_type' => 'stale']),
        'String',
      ]],
    );
    return $action;
  }

  protected function assertDeliveriesNameTheirTopic(int $action): void {
    $this->assertSame(
      ['delivery_mode' => 'subscribers', 'disable_notification' => FALSE, 'subscribers_of' => 'members_news'],
      unserialize(
        (string) CRM_Core_DAO::singleValueQuery(
          'SELECT action_params FROM civirule_rule_action WHERE id = %1',
          [1 => [$action, 'Integer']],
        ),
        ['allowed_classes' => FALSE],
      ),
    );
    $parameters = json_decode(
      (string) \Civi\Api4\Job::get(checkPermissions: FALSE)
        ->addWhere('id', '=', $this->job)
        ->execute()
        ->first()['parameters'],
      TRUE,
    );
    $this->assertSame(
      ['digest' => 'd', 'delivery_mode' => 'subscribers', 'subscribers_of' => 'daily_digest'],
      $parameters['actionParams'],
    );
    $this->assertSame(
      ['delivery_mode' => 'subscribers', 'subscribers_of' => 'members_news'],
      json_decode(
        (string) CRM_Core_DAO::singleValueQuery(
          "SELECT delivery FROM civicrm_herald_buffer WHERE group_hash = 'herald_test_topics'",
        ),
        TRUE,
      ),
    );
  }

  protected function assertTheSubscriptionsStay(): void {
    $field = \Civi\Api4\CustomField::get(checkPermissions: FALSE)
      ->addSelect('option_group_id:name')
      ->addWhere('custom_group_id:name', '=', 'herald_preferences')
      ->addWhere('name', '=', 'subscriptions')
      ->execute()
      ->first();
    $this->assertSame(SubscribableTopics::OPTION_GROUP, $field['option_group_id:name'] ?? NULL);
    $this->assertNull(
      CRM_Core_DAO::singleValueQuery(
        "SELECT entity_modified_date FROM civicrm_managed WHERE name = 'CustomField_herald_preferences_subscriptions'",
      ),
      'the reconcile moves the field, so later changes to it still apply',
    );
    $this->assertSame(
      0,
      \Civi\Api4\OptionGroup::get(checkPermissions: FALSE)
        ->addWhere('name', '=', 'herald_subscription_type')
        ->execute()
        ->count(),
    );
    $this->assertSame(1, SubscribableTopics::subscribers('completed_alert'));
    $stored = (array) \Civi\Api4\Contact::get(checkPermissions: FALSE)
      ->addSelect('herald_preferences.subscriptions')
      ->addWhere('id', '=', $this->contacts[0])
      ->execute()
      ->first()['herald_preferences.subscriptions'];
    sort($stored);
    $this->assertSame(['completed_alert', 'members_news'], $stored);
  }

}
