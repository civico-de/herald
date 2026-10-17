<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Api4\Contact;
use Civi\Herald\PreEditSnapshot;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unreadable entities, rows or fields skip the snapshot or report the
 * current value as unknown instead of failing the edit.
 *
 * @group headless
 */
class FieldTransitionSnapshotterTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    PreEditSnapshot::reset();
    FieldTransitionWatchList::reset();
  }

  protected function tearDown(): void {
    FieldTransitionWatchList::reset();
    parent::tearDown();
  }

  private function watch(string $entity, string $field): void {
    $conditionId = (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT id FROM civirule_condition WHERE class_name = %1',
      [1 => [FieldTransitionWatchList::ADAPTER_CLASS, 'String']],
    );
    $this->assertGreaterThan(0, $conditionId);
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule (name, label, trigger_id, is_active, created_date)
       VALUES (%1, %1, (SELECT MIN(id) FROM civirule_trigger), 1, NOW())',
      [1 => ['herald_test_snapshot_' . $field, 'String']],
    );
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_condition (rule_id, condition_id, condition_params) VALUES (LAST_INSERT_ID(), %1, %2)',
      [
        1 => [$conditionId, 'Integer'],
        2 => [serialize(['entity' => $entity, 'field' => $field]), 'String'],
      ],
    );
    FieldTransitionWatchList::reset();
  }

  private function contactId(): int {
    return (int) Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('organization_name', 'Herald snapshot fixture')
      ->execute()
      ->first()['id'];
  }

  public function testCapturesOnlyFieldsTheReadReturns(): void {
    $id = $this->contactId();
    $this->watch('Contact', 'do_not_phone');
    $this->watch('Contact', 'no_such_field');

    FieldTransitionSnapshotter::capture('Organization', $id);

    $this->assertFalse((bool) PreEditSnapshot::get('Contact', $id, 'do_not_phone'));
    $this->assertNotNull(PreEditSnapshot::get('Contact', $id, 'do_not_phone'));
    $this->assertNull(PreEditSnapshot::get('Contact', $id, 'no_such_field'));
  }

  public function testAnOptionSuffixReadsTheOptionName(): void {
    $id = (int) Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Herald')
      ->addValue('gender_id:name', 'Female')
      ->execute()
      ->first()['id'];
    $this->watch('Contact', 'gender_id:name');

    FieldTransitionSnapshotter::capture('Individual', $id);

    $this->assertSame('Female', PreEditSnapshot::get('Contact', $id, 'gender_id:name'));
    $this->assertSame(
      ['known' => TRUE, 'value' => 'Female'],
      FieldTransitionSnapshotter::currentValue('Contact', $id, 'gender_id:name'),
    );
  }

  public function testAJoinedFieldReadsTheReferencedRecord(): void {
    $employer = $this->contactId();
    $id = (int) Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Herald')
      ->addValue('employer_id', $employer)
      ->execute()
      ->first()['id'];
    $this->watch('Contact', 'employer_id.organization_name');

    FieldTransitionSnapshotter::capture('Individual', $id);

    $this->assertSame('Herald snapshot fixture', PreEditSnapshot::get('Contact', $id, 'employer_id.organization_name'));
    $this->assertSame(
      ['known' => TRUE, 'value' => 'Herald snapshot fixture'],
      FieldTransitionSnapshotter::currentValue('Contact', $id, 'employer_id.organization_name'),
    );
  }

  public function testUnreadableEntitySkipsTheSnapshot(): void {
    $this->watch('NoSuchEntity', 'status');

    FieldTransitionSnapshotter::capture('NoSuchEntity', 7);

    $this->assertNull(PreEditSnapshot::get('NoSuchEntity', 7, 'status'));
  }

  public function testMissingRowOrIdSkipsTheSnapshot(): void {
    $this->watch('Contact', 'do_not_phone');

    FieldTransitionSnapshotter::capture('Organization', 0);
    FieldTransitionSnapshotter::capture('Organization', 999_999_999);

    $this->assertNull(PreEditSnapshot::get('Contact', 0, 'do_not_phone'));
    $this->assertNull(PreEditSnapshot::get('Contact', 999_999_999, 'do_not_phone'));
  }

  public function testCurrentValueReadsTheLiveRow(): void {
    $id = $this->contactId();
    $this->assertSame(
      ['known' => TRUE, 'value' => 'Herald snapshot fixture'],
      FieldTransitionSnapshotter::currentValue('Contact', $id, 'organization_name'),
    );
  }

  public function testCurrentValueIsUnknownWhenUnreadable(): void {
    $id = $this->contactId();
    $unknown = ['known' => FALSE, 'value' => NULL];
    $this->assertSame($unknown, FieldTransitionSnapshotter::currentValue('NoSuchEntity', $id, 'status'));
    $this->assertSame($unknown, FieldTransitionSnapshotter::currentValue('Contact', 999_999_999, 'organization_name'));
    $this->assertSame($unknown, FieldTransitionSnapshotter::currentValue('Contact', $id, 'no_such_field'));
  }

}
