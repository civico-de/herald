<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The system checks herald runs on its own delivery pipeline.
 *
 * The distinction under test is what made a single check useless in
 * production: a parked group stays in the buffer table on purpose, so counting
 * it as backlog left "flush job not delivering" standing forever, long after
 * the flush job had given up on it and moved on.
 *
 * @group headless
 */
class BufferChecksTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private const GROUP = 'herald-test-bufferchecks';

  private AlertBuffer $buffer;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_herald_buffer');
    $this->buffer = new AlertBuffer();
  }

  /**
   * @return array<string, array<string, mixed>> The herald buffer messages by name.
   */
  private function messages(): array {
    $messages = [];
    \Civi::service('herald.buffer_checks')->hook_civicrm_check($messages);
    $out = [];
    foreach ($messages as $message) {
      $out[$message->getName()] = $message->toArray();
    }
    return $out;
  }

  private function bufferOverdue(): int {
    $this->buffer->add(self::GROUP, 5, 'alert body', ['channel' => 'telegram']);
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_herald_buffer SET created_date = DATE_SUB(NOW(), INTERVAL 60 MINUTE) WHERE group_hash = %1',
      [1 => [self::GROUP, 'String']],
    );
    return (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_herald_buffer');
  }

  public function testAnEmptyBufferProducesNoMessage(): void {
    $this->assertSame([], $this->messages());
  }

  public function testFreshAlertInsideItsWindowIsNoBacklog(): void {
    $this->buffer->add(self::GROUP, 5, 'alert body', ['channel' => 'telegram']);
    $this->assertSame([], $this->messages());
  }

  public function testOverdueAlertIsABacklogError(): void {
    $this->bufferOverdue();
    $messages = $this->messages();
    $this->assertSame([BufferChecks::BACKLOG], array_keys($messages));
    $this->assertSame('error', $messages[BufferChecks::BACKLOG]['severity']);
    $this->assertStringContainsString('1 buffered alert(s)', $messages[BufferChecks::BACKLOG]['message']);
  }

  public function testParkedAlertMovesFromBacklogToDeadLetter(): void {
    $id = $this->bufferOverdue();
    (new DeadLetter($this->buffer))->park([$id], 'no_recipients', 'nobody resolved');

    $messages = $this->messages();
    $this->assertSame([BufferChecks::DEAD_LETTER], array_keys($messages), 'a parked row is not a backlog');
    $this->assertSame('warning', $messages[BufferChecks::DEAD_LETTER]['severity']);
    $this->assertStringContainsString('Herald.getParkedAlerts', $messages[BufferChecks::DEAD_LETTER]['message']);
  }

  public function testReactivatingAParkedAlertPutsItBackIntoTheBacklog(): void {
    $id = $this->bufferOverdue();
    $deadLetter = new DeadLetter($this->buffer);
    $deadLetter->park([$id], 'max_attempts', 'channel down');
    $deadLetter->reactivate([$id]);

    $this->assertSame([BufferChecks::BACKLOG], array_keys($this->messages()));
  }

  public function testACheckThatCannotCheckReportsAnError(): void {
    $message = BufferChecks::guarded(
      BufferChecks::BACKLOG,
      static fn (): never => throw new \RuntimeException('table <gone>'),
    );
    $this->assertNotNull($message);
    $this->assertSame(BufferChecks::BACKLOG, $message->getName());
    $this->assertSame('error', $message->toArray()['severity']);
    $this->assertStringContainsString('table &lt;gone&gt;', $message->getMessage());
  }

  public function testTheChecksAppearInSystemCheck(): void {
    $this->bufferOverdue();
    $names = \Civi\Api4\System::check(FALSE)
      ->addWhere('name', 'LIKE', 'herald_buffer_%')
      ->execute()
      ->column('name');
    $this->assertSame([BufferChecks::BACKLOG], $names);
  }

}
