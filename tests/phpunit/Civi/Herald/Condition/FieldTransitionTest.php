<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the generic field-transition comparison.
 *
 * @group unit
 */
class FieldTransitionTest extends TestCase {

  /**
   * @return array<string, array{0: string, 1: mixed, 2: string, 3: bool}>
   */
  public static function compareCases(): array {
    return [
      'equals numeric' => ['=', '2', '2', TRUE],
      'equals numeric loose types' => ['=', 2, '2.0', TRUE],
      'equals string' => ['=', 'Completed', 'Completed', TRUE],
      'equals string trimmed' => ['=', ' Completed ', 'Completed', TRUE],
      'equals mismatch' => ['=', '2', '1', FALSE],
      'not equals' => ['!=', '2', '1', TRUE],
      'not equals mismatch' => ['!=', '2', '2', FALSE],
      'greater than' => ['>', '10', '5', TRUE],
      'greater than mismatch' => ['>', '5', '10', FALSE],
      'greater than non-numeric' => ['>', 'abc', '5', FALSE],
      'less than' => ['<', '3', '5', TRUE],
      'gte boundary' => ['>=', '5', '5', TRUE],
      'lte boundary' => ['<=', '5', '5', TRUE],
      'is_empty on null' => ['is_empty', NULL, '', TRUE],
      'is_empty on empty string' => ['is_empty', '', '', TRUE],
      'is_empty on zero is false' => ['is_empty', '0', '', FALSE],
      'not_empty on value' => ['not_empty', 'x', '', TRUE],
      'not_empty on null' => ['not_empty', NULL, '', FALSE],
      'equals on array is false' => ['=', ['x'], 'x', FALSE],
      'unknown operator' => ['~', '1', '1', FALSE],
      // Numeric equality only while float is lossless; huge integers must not
      // collapse (9007199254740992.0 === 9007199254740993.0 as floats).
      'huge ints not equal' => ['=', '9007199254740993', '9007199254740992', FALSE],
      'huge ints equal' => ['=', '9007199254740993', '9007199254740993', TRUE],
      'huge ints equal with leading zero' => ['=', '09007199254740993', '9007199254740993', TRUE],
      'huge int ordering' => ['>', '9007199254740993', '9007199254740992', TRUE],
      'huge int ordering reverse' => ['<', '9007199254740992', '9007199254740993', TRUE],
      'huge negative int ordering' => ['<', '-9007199254740993', '-9007199254740992', TRUE],
      'ordering with different lengths' => ['>', '10000000000000000', '999999999999999', TRUE],
      'leading zeros numeric compare' => ['=', '00123', '123', TRUE],
      'equals bool false vs 0' => ['=', FALSE, '0', TRUE],
      'equals bool true vs 1' => ['=', TRUE, '1', TRUE],
      'equals bool false vs 1' => ['=', FALSE, '1', FALSE],
      'not equals bool false vs 1' => ['!=', FALSE, '1', TRUE],
      'ordering on bool' => ['<', FALSE, '1', TRUE],
    ];
  }

  /**
   * @dataProvider compareCases
   */
  public function testCompare(string $operator, mixed $actual, string $expected, bool $result): void {
    $this->assertSame($result, FieldTransition::compare($operator, $actual, $expected));
  }

  public function testBothSidesConfiguredMustBothMatch(): void {
    $before = FieldTransition::side('=', '2');
    $after = FieldTransition::side('=', '1');
    $known = static fn (mixed $v): array => ['known' => TRUE, 'value' => $v];
    $this->assertTrue(FieldTransition::matches($before, $after, $known('2'), '1'));
    $this->assertFalse(FieldTransition::matches($before, $after, $known('2'), '9'));
    $this->assertFalse(FieldTransition::matches($before, $after, $known('9'), '1'));
  }

  public function testUnconfiguredBeforeSideMatchesAnyPreviousValue(): void {
    $before = FieldTransition::side('', '');
    $after = FieldTransition::side('=', '1');
    $this->assertTrue(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => 'whatever'], '1'));
    // One-sided conditions require a verifiable change, so an unknown
    // previous value fails them (a missed alert beats a false one).
    $this->assertFalse(FieldTransition::matches($before, $after, ['known' => FALSE, 'value' => NULL], '1'));
  }

  public function testUnconfiguredAfterSideMatchesAnyCurrentValue(): void {
    $before = FieldTransition::side('=', '2');
    $after = FieldTransition::side('', '');
    $this->assertTrue(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => '2'], 'anything'));
  }

  public function testNotEmptyAfterSideMatchesAnUnchangedValue(): void {
    // "Previous status was Pending", also on edits that keep it Pending.
    $before = FieldTransition::side('=', '2');
    $after = FieldTransition::side('not_empty', '');
    $this->assertTrue(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => '2'], '2'));
    $this->assertTrue(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => '2'], '1'));
    $this->assertFalse(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => '1'], '1'));
    $this->assertFalse(FieldTransition::matches($before, $after, ['known' => FALSE, 'value' => NULL], '2'));
  }

  public function testOneSidedConditionRequiresAnActualChange(): void {
    // "after = 0", row already at 0, unrelated edit: must NOT fire.
    $before = FieldTransition::side('', '');
    $after = FieldTransition::side('=', '0');
    $this->assertFalse(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => '0'], '0'));
    $this->assertFalse(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => 0], '0'));
    $this->assertTrue(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => '1'], '0'));
    // Before-only conditions get the same guard.
    $beforeOnly = FieldTransition::side('=', 'Pending');
    $open = FieldTransition::side('', '');
    $this->assertFalse(FieldTransition::matches(
      $beforeOnly,
      $open,
      ['known' => TRUE, 'value' => 'Pending'],
      'Pending',
    ));
    $this->assertTrue(FieldTransition::matches(
      $beforeOnly,
      $open,
      ['known' => TRUE, 'value' => 'Pending'],
      'Completed',
    ));
  }

  public function testTwoSidedConditionDoesNotRequireAChange(): void {
    // Both sides explicit: the admin stated the exact transition, including a
    // degenerate same-value one — keep that semantics untouched.
    $before = FieldTransition::side('>=', '0');
    $after = FieldTransition::side('=', '5');
    $this->assertTrue(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => '5'], '5'));
  }

  public function testSameValueUsesEqualitySemantics(): void {
    $this->assertTrue(FieldTransition::sameValue('0', 0));
    $this->assertTrue(FieldTransition::sameValue(' 2 ', '2.0'));
    $this->assertFalse(FieldTransition::sameValue(NULL, '0'));
    $this->assertTrue(FieldTransition::sameValue(NULL, NULL));
    $this->assertTrue(FieldTransition::sameValue(a: FALSE, b: '0'));
    $this->assertTrue(FieldTransition::sameValue(a: '1', b: TRUE));
    $this->assertFalse(FieldTransition::sameValue('9007199254740993', '9007199254740992'));
  }

  public function testNoSideConfiguredNeverMatches(): void {
    $this->assertFalse(FieldTransition::matches(
      FieldTransition::side('', ''),
      FieldTransition::side('', ''),
      ['known' => TRUE, 'value' => '1'],
      '2',
    ));
  }

  public function testConfiguredBeforeSideFailsOnUnknownPreviousValue(): void {
    $this->assertFalse(FieldTransition::matches(
      FieldTransition::side('=', '2'),
      FieldTransition::side('=', '1'),
      ['known' => FALSE, 'value' => NULL],
      '1',
    ));
  }

  public function testSideNormalisesUnknownOperatorToUnconfigured(): void {
    $this->assertSame(['operator' => '', 'value' => 'x'], FieldTransition::side('DROP TABLE', 'x'));
    $this->assertSame(['operator' => '>=', 'value' => '5'], FieldTransition::side(' >= ', 5));
    $this->assertSame(['operator' => '', 'value' => ''], FieldTransition::side(NULL, NULL));
  }

}
