<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

/**
 * The test-message screen's two calls, against the email channel and the
 * mailing spool: Herald.send with schema-keyed config, and Herald.publish
 * through a route saved by the topics screen.
 *
 * @group headless
 */
class TestMessageTest extends AdminTestCase {

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->set('mailing_backend', [
      'outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB,
    ]);
    \Civi::settings()->set('herald_email_from', '"Herald" <herald@example.org>');
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
  }

  public function testChannelTargetSendsWithTheFormConfig(): void {
    $rows = self::call('send', [
      'channel' => 'email',
      'message' => 'Test from the admin screen',
      'subject' => 'herald test',
      'format' => 'text',
      'config' => ['email_to' => 'ops@example.org'],
    ]);
    $this->assertTrue($rows[0]['sent']);
    $this->assertSame([], $rows[0]['unknown_config_keys']);
    $this->assertSpooled('ops@example.org', 'herald test', 'Test from the admin screen');
  }

  public function testTopicTargetPublishesThroughTheSavedRoute(): void {
    \Civi::settings()->set('herald_topics', []);
    self::call('saveTopic', [
      'topic' => 'default',
      'route' => ['channel' => 'email', 'email_to' => 'routes@example.org'],
    ]);
    $rows = self::call('publish', ['topic' => 'smoke', 'message' => 'Routed test', 'format' => 'text']);
    $this->assertSame([['topic' => 'smoke', 'buffered' => FALSE, 'channel' => 'email']], $rows);
    $this->assertSpooled('routes@example.org', NULL, 'Routed test');
  }

  public function testATopicToItsSubscribersReachesThemOnTheirChannel(): void {
    \Civi::settings()->set('herald_topics', ['default' => ['channel' => 'email']]);
    self::call('saveTopic', [
      'topic' => 'news',
      'route' => ['delivery_mode' => 'subscribers'],
      'subscribable' => TRUE,
    ]);
    \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Subscriber')
      ->addChain('subscription', \Civi\Api4\CustomValue::create('herald_subscription', checkPermissions: FALSE)
        ->setValues(['entity_id' => '$id', 'channel' => 'email', 'topics' => ['news']]))
      ->addChain('email', \Civi\Api4\Email::create(checkPermissions: FALSE)
        ->addValue('contact_id', '$id')
        ->addValue('email', 'subscriber@example.org'))
      ->execute();
    $rows = self::call('publish', ['topic' => 'news', 'message' => 'Fanned out', 'format' => 'text']);
    $this->assertSame([['topic' => 'news', 'buffered' => FALSE, 'channel' => NULL]], $rows);
    $this->assertSpooled('subscriber@example.org', NULL, 'Fanned out');
  }

  private function assertSpooled(string $to, ?string $subject, string $body): void {
    $spool = \CRM_Core_DAO::executeQuery(
      'SELECT recipient_email, headers, body FROM civicrm_mailing_spool',
    )->fetchAll();
    $this->assertCount(1, $spool);
    $this->assertStringContainsString($to, (string) $spool[0]['recipient_email']);
    if ($subject !== NULL) {
      $this->assertStringContainsString($subject, (string) $spool[0]['headers']);
    }
    $this->assertStringContainsString($body, (string) $spool[0]['body']);
  }

}
