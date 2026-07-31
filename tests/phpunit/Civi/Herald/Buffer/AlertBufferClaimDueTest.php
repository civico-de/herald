<?php

namespace Civi\Herald\Buffer;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * add() → claimDue() → deleteRows() against real buffer rows.
 *
 * The interesting part is not the SQL but the narrowing on the way out: MySQL
 * speaks text on the wire, so a DAO result row hands every column back as a
 * string (or NULL). claimDue() is what turns those strings into the
 * `list<int> $ids` / `list<float> $amounts` / `int $window` that the flush job
 * and AlertBuffer::compose() are typed against — hence the assertions below
 * check types, not just values.
 *
 * No DDL here: the install step (CRM_Herald_Upgrader::install) already created
 * the table, and a CREATE TABLE would implicitly commit and defeat the
 * transactional rollback this test relies on.
 *
 * @group headless
 */
class AlertBufferClaimDueTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const DELIVERY = [
    'channel' => 'telegram',
    'chat_id' => '-1001234',
    'aggregate_minutes' => 15,
  ];

  private AlertBuffer $buffer;

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    $this->buffer = new AlertBuffer();
  }

  /**
   * Age a group so its oldest entry is past the window — add() always stamps
   * created_date with NOW(), so nothing is ever due within one test run.
   */
  private function backdate(string $groupHash, int $minutes): void {
    \CRM_Core_DAO::executeQuery(
      'UPDATE `' . AlertBuffer::TABLE . '` SET created_date = DATE_SUB(NOW(), INTERVAL %2 MINUTE) WHERE group_hash = %1',
      [1 => [$groupHash, 'String'], 2 => [$minutes, 'Integer']]
    );
  }

  /**
   * claimDue() reports every due group in the instance; pick ours out.
   *
   * @return array<string, mixed>|null
   */
  private function claim(string $groupHash): ?array {
    foreach ($this->buffer->claimDue() as $group) {
      if ($group['group_hash'] === $groupHash) {
        return $group;
      }
    }
    return NULL;
  }

  private function countRows(string $groupHash): int {
    return (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM `' . AlertBuffer::TABLE . '` WHERE group_hash = %1',
      [1 => [$groupHash, 'String']]
    );
  }

  public function testDueGroupComesBackNarrowedToTheDeclaredTypes(): void {
    $hash = 'herald-test-due';
    $this->buffer->add($hash, 15, '🎟 first alert', self::DELIVERY, 60.0);
    $this->buffer->add($hash, 15, '🎟 second alert', self::DELIVERY, 35.5);
    $this->backdate($hash, 20);

    $group = $this->claim($hash);

    $this->assertNotNull($group, 'a group older than its window must be due');
    $this->assertSame(['🎟 first alert', '🎟 second alert'], $group['bodies'], 'insertion order preserved');
    // assertSame, not assertEquals: the DAO said "15", "60.00", "7"; these
    // would still pass a loose comparison as strings.
    $this->assertSame(15, $group['window']);
    $this->assertSame([60.0, 35.5], $group['amounts']);
    $this->assertCount(2, $group['ids']);
    $this->assertContainsOnly('int', $group['ids']);
    // The delivery params survive the JSON round trip unchanged — the flush
    // job hands them straight to AbstractSend::deliver().
    $this->assertSame(self::DELIVERY, $group['delivery']);
  }

  public function testGroupIsNotDueBeforeItsWindowElapsed(): void {
    $hash = 'herald-test-fresh';
    $this->buffer->add($hash, 15, 'too young to send', self::DELIVERY, 10.0);

    $this->assertNull($this->claim($hash));
    $this->assertSame(1, $this->countRows($hash), 'claimDue must not consume rows');
  }

  /**
   * The window is the LONGEST one in the group (MAX(window_minutes)), and the
   * group only becomes due once its OLDEST entry has waited that long.
   */
  public function testWindowIsTheLongestOneInTheGroup(): void {
    $hash = 'herald-test-mixed-windows';
    $this->buffer->add($hash, 5, 'short window', self::DELIVERY);
    $this->buffer->add($hash, 30, 'long window', self::DELIVERY);
    $this->backdate($hash, 10);
    $this->assertNull($this->claim($hash), '10 min is past the 5 min entry but not past the 30 min one');

    $this->backdate($hash, 40);
    $group = $this->claim($hash);
    $this->assertNotNull($group);
    $this->assertSame(30, $group['window']);
  }

  public function testRowsWithoutAnAmountContributeNoAmount(): void {
    $hash = 'herald-test-no-amount';
    $this->buffer->add($hash, 5, 'no money involved', self::DELIVERY);
    $this->buffer->add($hash, 5, 'this one has a price', self::DELIVERY, 12.34);
    $this->backdate($hash, 10);

    $group = $this->claim($hash);
    $this->assertNotNull($group);
    // NULL is skipped rather than summed as 0.00 — an alert without a monetary
    // value must not drag the aggregate header's total down.
    $this->assertSame([12.34], $group['amounts']);
    $this->assertCount(2, $group['bodies']);
  }

  /**
   * A delivery column that is not decodable JSON (hand-edited row, truncated
   * write) must not take the whole flush down with it: the bodies still
   * compose, the params fall back to empty, and deliver() is what complains.
   */
  public function testUndecodableDeliveryFallsBackToEmptyParams(): void {
    $hash = 'herald-test-broken-delivery';
    $this->buffer->add($hash, 5, 'body survives', self::DELIVERY);
    \CRM_Core_DAO::executeQuery(
      'UPDATE `' . AlertBuffer::TABLE . '` SET delivery = %2 WHERE group_hash = %1',
      [1 => [$hash, 'String'], 2 => ['{not valid json', 'String']]
    );
    $this->backdate($hash, 10);

    $group = $this->claim($hash);
    $this->assertNotNull($group);
    $this->assertSame(['body survives'], $group['bodies']);
    $this->assertSame([], $group['delivery']);
  }

  public function testDeleteRowsRemovesOnlyTheClaimedGroup(): void {
    $mine = 'herald-test-delete-mine';
    $other = 'herald-test-delete-other';
    $this->buffer->add($mine, 5, 'sent', self::DELIVERY);
    $this->buffer->add($other, 5, 'still waiting', self::DELIVERY);
    $this->backdate($mine, 10);

    $group = $this->claim($mine);
    $this->assertNotNull($group);
    $this->buffer->deleteRows($group['ids']);

    $this->assertSame(0, $this->countRows($mine));
    $this->assertSame(1, $this->countRows($other));
  }

  public function testDeleteRowsWithoutIdsIsANoop(): void {
    $hash = 'herald-test-delete-none';
    $this->buffer->add($hash, 5, 'untouched', self::DELIVERY);

    $this->buffer->deleteRows([]);

    $this->assertSame(1, $this->countRows($hash));
  }

}
