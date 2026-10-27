<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class ActivityChannelTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var array<string, mixed>|null
   */
  private ?array $created = NULL;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->created = NULL;
  }

  public function testCreatesScheduledTaskForAssignee(): void {
    $channel = new ActivityChannel(
      function (array $values): void {
        $this->created = $values;
      },
      static fn (string $group, string $name): int => match ([$group, $name]) {
        ['activity_type', 'herald_notification'] => 3,
        ['activity_status', 'Scheduled'] => 1,
        default => throw new \LogicException('Unexpected option.'),
      },
    );

    $channel->send('<p>Three unmatched bank transactions</p>', [
      'activity_assignee_contact_id' => 42,
      'activity_target_contact_id' => 84,
      'activity_subject' => 'Review bank import',
    ]);

    $this->assertSame(
      [
        'activity_type_id' => 3,
        'status_id' => 1,
        'source_contact_id' => 42,
        'assignee_contact_id' => [42],
        'subject' => 'Review bank import',
        'details' => '<p>Three unmatched bank transactions</p>',
        'target_contact_id' => [84],
      ],
      $this->created,
    );
  }

  public function testDefaultCreatorWritesActivityThroughApi4(): void {
    $sourceId = (int) \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Demo')
      ->addValue('last_name', 'Coordinator')
      ->execute()
      ->single()['id'];
    $targetId = (int) \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Demo')
      ->addValue('last_name', 'Member')
      ->execute()
      ->single()['id'];

    (new ActivityChannel())->send('Please review the overdue membership dues.', [
      'activity_assignee_contact_id' => $sourceId,
      'activity_target_contact_id' => $targetId,
      'activity_subject' => 'Review overdue dues',
      'activity_format' => 'text',
    ]);

    $activity = \Civi\Api4\Activity::get(checkPermissions: FALSE)
      ->addSelect(
        'subject',
        'activity_type_id:name',
        'status_id:name',
        'source_contact_id',
        'target_contact_id',
        'assignee_contact_id',
      )
      ->addWhere('subject', '=', 'Review overdue dues')
      ->execute()
      ->single();
    $this->assertSame('herald_notification', $activity['activity_type_id:name']);
    $this->assertSame('Scheduled', $activity['status_id:name']);
    $this->assertSame($sourceId, $activity['source_contact_id']);
    $this->assertSame([$targetId], $activity['target_contact_id']);
    $this->assertSame([$sourceId], $activity['assignee_contact_id']);
  }

  public function testSupportsCustomTypeStatusSourceAndTextBody(): void {
    $values = ActivityChannel::buildActivityValues("First line\nSecond &lt;tag&gt;", [
      'activity_assignee_contact_id' => '42',
      'activity_source_contact_id' => 7,
      'activity_type' => 'Follow Up',
      'activity_status' => 'Completed',
      'activity_format' => 'text',
    ]);

    $this->assertIsInt($values['activity_type_id']);
    $this->assertIsInt($values['status_id']);
    $this->assertSame(7, $values['source_contact_id']);
    $this->assertSame('First line Second <tag>', $values['subject']);
    $this->assertSame('First line<br />' . "\n" . 'Second &lt;tag&gt;', $values['details']);
  }

  public function testRequiresPositiveAssignee(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/activity_assignee_contact_id/');
    ActivityChannel::buildActivityValues('message', ['activity_assignee_contact_id' => 0]);
  }

  public function testResolvesAssigneeByExternalIdentifier(): void {
    $opsId = self::organisation('herald-ops');

    $values = ActivityChannel::buildActivityValues(
      'Check failed',
      [
        'activity_assignee_external_identifier' => ' herald-ops ',
      ],
      static fn (string $group, string $name): int => 1,
    );

    $this->assertSame([$opsId], $values['assignee_contact_id']);
    $this->assertSame($opsId, $values['source_contact_id']);
  }

  public function testAssigneeContactIdBeatsExternalIdentifier(): void {
    $values = ActivityChannel::buildActivityValues(
      'Check failed',
      [
        'activity_assignee_contact_id' => 42,
        'activity_assignee_external_identifier' => 'nobody-has-this',
      ],
      static fn (string $group, string $name): int => 1,
    );

    $this->assertSame([42], $values['assignee_contact_id']);
  }

  public function testUnknownExternalIdentifierFails(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('no contact outside the trash has the external identifier "nobody-has-this"');
    ActivityChannel::buildActivityValues(
      'Check failed',
      [
        'activity_assignee_external_identifier' => 'nobody-has-this',
      ],
      static fn (string $group, string $name): int => 1,
    );
  }

  public function testTrashedContactIsNoAssignee(): void {
    $opsId = self::organisation('herald-ops');
    \Civi\Api4\Contact::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $opsId)
      ->addValue('is_deleted', TRUE)
      ->execute();

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('external identifier "herald-ops"');
    ActivityChannel::buildActivityValues(
      'Check failed',
      [
        'activity_assignee_external_identifier' => 'herald-ops',
      ],
      static fn (string $group, string $name): int => 1,
    );
  }

  private static function organisation(string $externalIdentifier): int {
    return (int) \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('organization_name', 'Ops')
      ->addValue('external_identifier', $externalIdentifier)
      ->execute()
      ->single()['id'];
  }

  public function testRejectsUnknownBodyFormat(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/activity_format/');
    ActivityChannel::buildActivityValues('message', [
      'activity_assignee_contact_id' => 1,
      'activity_format' => 'markdown',
    ]);
  }

  public function testDeclaresSemanticFields(): void {
    $channel = new ActivityChannel();
    $this->assertSame(
      [
        'subject' => 'activity_subject',
        'format' => 'activity_format',
      ],
      $channel->getSemanticFields(),
    );
    $this->assertArrayHasKey('activity_assignee_contact_id', $channel->getConfigSchema());
  }

  public function testExplicitSubjectIsPlainTextAndFitsCoreColumn(): void {
    $subject = '<strong>' . str_repeat('x', 300) . '</strong>';
    $values = ActivityChannel::buildActivityValues(
      'body',
      [
        'activity_assignee_contact_id' => 42,
        'activity_subject' => $subject,
      ],
      static fn (string $group, string $name): int => 1,
    );

    $this->assertSame(255, mb_strlen($values['subject']));
    $this->assertStringNotContainsString('<strong>', $values['subject']);
    $this->assertStringEndsWith('...', $values['subject']);
  }

  public function testManagedActivityTypeNeverDeletesHistoryOnCleanup(): void {
    $managed = require dirname(__DIR__, 5) . '/managed/ActivityType.mgd.php';

    $this->assertSame('never', $managed[0]['cleanup']);
    $this->assertTrue($managed[0]['params']['values']['is_reserved']);
  }

}
