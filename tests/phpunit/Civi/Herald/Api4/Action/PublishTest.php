<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\MessageTemplate;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Herald.publish — topic routing into the aggregation buffer.
 *
 * The point under test is the aggregation KEY: everything published to one
 * topic with one route has to land in one group, otherwise the window only
 * delays each message instead of collecting them.
 *
 * @group headless
 */
class PublishTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const WORKFLOW = 'herald_test_publish';

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    // No DDL: the install step created the buffer table, and a CREATE TABLE
    // would implicitly commit and defeat the transactional rollback.
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_herald_buffer');
    \Civi::settings()->set('herald_topics', [
      'test_topic' => [
        'channel' => 'email',
        'email_to' => 'ops@example.org',
        'aggregate_minutes' => 15,
      ],
    ]);
    MessageTemplate::create(FALSE)
      ->addValue('msg_title', 'Herald test publish')
      ->addValue('workflow_name', self::WORKFLOW)
      // A per-event subject — exactly what must stay out of the group key.
      ->addValue('msg_subject', 'Alert {$serial}')
      ->addValue('msg_html', 'body {$serial}')
      ->addValue('is_default', TRUE)
      ->addValue('is_active', TRUE)
      ->execute();
  }

  private function publish(string $serial): void {
    civicrm_api4('Herald', 'publish', [
      'checkPermissions' => FALSE,
      'topic' => 'test_topic',
      'template' => self::WORKFLOW,
      'vars' => ['serial' => $serial],
    ]);
  }

  public function testMessagesOfOneTopicShareOneGroupDespiteRenderedSubjects(): void {
    $this->publish('one');
    $this->publish('two');

    $this->assertSame(
      2,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_herald_buffer'),
      'both publishes should be buffered',
    );
    $this->assertSame(
      1,
      (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(DISTINCT group_hash) FROM civicrm_herald_buffer'),
      'one topic + one route = one aggregation group, whatever each message rendered as its subject',
    );
    // The subjects really do differ per message — which is why the key must be
    // taken from the route params, before the rendered subject is applied.
    $this->assertSame(
      2,
      (int) \CRM_Core_DAO::singleValueQuery(
        "SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(delivery, '$.email_subject'))) FROM civicrm_herald_buffer",
      ),
    );
  }

}
