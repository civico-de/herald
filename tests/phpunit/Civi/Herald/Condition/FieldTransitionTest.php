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
    // Even an unknown previous value is fine when the before side is open.
    $this->assertTrue(FieldTransition::matches($before, $after, ['known' => FALSE, 'value' => NULL], '1'));
  }

  public function testUnconfiguredAfterSideMatchesAnyCurrentValue(): void {
    $before = FieldTransition::side('=', '2');
    $after = FieldTransition::side('', '');
    $this->assertTrue(FieldTransition::matches($before, $after, ['known' => TRUE, 'value' => '2'], 'anything'));
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
