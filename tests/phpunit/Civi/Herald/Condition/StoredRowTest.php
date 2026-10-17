<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Api4\OptionValue;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Stored copies resolve as APIv4 get resolves them, whether APIv3 (strings,
 * unpacked lists) or APIv4 (typed values) produced them.
 *
 * @group headless
 */
class StoredRowTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private const MULTI = 'preferred_communication_method';

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testADisabledOptionStillResolvesToItsName(): void {
    OptionValue::update(FALSE)
      ->addWhere('option_group_id:name', '=', 'gender')
      ->addWhere('name', '=', 'Other')
      ->addValue('is_active', FALSE)
      ->execute();
    $this->assertSame(
      ['known' => TRUE, 'value' => 'Other'],
      StoredRow::value('Contact', 'gender_id:name', ['gender_id' => '3']),
    );
  }

  /**
   * APIv3 returns "" for an empty column, where APIv4 returns NULL.
   */
  public function testAnEmptyOptionFieldIsKnownAndEmpty(): void {
    $this->assertSame(
      ['known' => TRUE, 'value' => NULL],
      StoredRow::value('Contact', 'gender_id:name', ['gender_id' => '']),
    );
  }

  public function testAnUnsuffixedFieldKeepsTheRawValue(): void {
    $this->assertSame(
      ['known' => TRUE, 'value' => '3'],
      StoredRow::value('Contact', 'gender_id', ['gender_id' => '3']),
    );
  }

  /**
   * APIv3 puts the label in place of an unpadded multi-value ID, so a value
   * without an option cannot be told apart from such a label.
   */
  public function testAValueWithoutOptionIsUnknown(): void {
    $this->assertSame(
      ['known' => FALSE, 'value' => NULL],
      StoredRow::value('Contact', 'gender_id:name', ['gender_id' => '999']),
    );
    $this->assertSame(
      ['known' => FALSE, 'value' => NULL],
      StoredRow::value('Contact', self::MULTI . ':name', [self::MULTI => 'Phone']),
    );
  }

  public function testASerializedFieldResolvesEachValue(): void {
    $row = [
      self::MULTI =>
        \CRM_Core_DAO::VALUE_SEPARATOR . '1' . \CRM_Core_DAO::VALUE_SEPARATOR . '2' . \CRM_Core_DAO::VALUE_SEPARATOR,
    ];
    $this->assertSame(['known' => TRUE, 'value' => ['1', '2']], StoredRow::value('Contact', self::MULTI, $row));
    $this->assertSame(
      ['known' => TRUE, 'value' => ['Phone', 'Email']],
      StoredRow::value('Contact', self::MULTI . ':name', $row),
    );
  }

  public function testAnUnpackedListAsApiv3ReturnsItResolvesEachValue(): void {
    $this->assertSame(
      ['known' => TRUE, 'value' => ['Phone', 'Email']],
      StoredRow::value('Contact', self::MULTI . ':name', [self::MULTI => ['1', '2']]),
    );
  }

  public function testATypedIdAsApiv4ReturnsItResolves(): void {
    $this->assertSame(
      ['known' => TRUE, 'value' => 'Other'],
      StoredRow::value('Contact', 'gender_id:name', ['gender_id' => 3]),
    );
  }

  public function testAnUnreadableValueIsUnknown(): void {
    $this->assertSame(
      ['known' => FALSE, 'value' => NULL],
      StoredRow::value('Contact', 'gender_id:name', ['gender_id' => new \stdClass()]),
    );
  }

  public function testAnEmptySerializedFieldIsAnEmptyList(): void {
    $this->assertSame(['known' => TRUE, 'value' => []], StoredRow::value('Contact', self::MULTI . ':name', [
      self::MULTI => '',
    ]));
  }

  public function testAFieldMissingFromTheRowIsUnknown(): void {
    $this->assertSame(
      ['known' => FALSE, 'value' => NULL],
      StoredRow::value('Contact', 'gender_id:name', ['custom_5' => '3']),
    );
  }

  public function testAnUnknownEntityIsUnknown(): void {
    $this->assertSame(
      ['known' => FALSE, 'value' => NULL],
      StoredRow::value('NoSuchEntity', 'gender_id:name', ['gender_id' => '3']),
    );
  }

}
