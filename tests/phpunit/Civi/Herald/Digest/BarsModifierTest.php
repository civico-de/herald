<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * The herald_bars modifier on the rows of a grouped source. Headless: problems are translated.
 *
 * @group headless
 */
class BarsModifierTest extends TestCase implements HeadlessInterface {

  private const ROWS = [
    ['form' => 'Spring appeal', 'type' => 'Donation', 'sum' => 40],
    ['form' => 'Shop', 'type' => 'Sales', 'sum' => '10.00'],
    ['form' => '', 'type' => 'Donation', 'sum' => NULL],
  ];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testValuesLineUpBeforeBarsSizedByTheLargest(): void {
    self::assertSame(
      "Spring appeal   40  ████████████\nShop            10  ███\n(none)           0",
      BarsModifier::apply(self::ROWS, 'sum', 'form'),
    );
  }

  public function testMoneyFormatsTheValuesAsAmounts(): void {
    self::assertStringStartsWith(
      'Spring appeal   '
      . \Civi::format()->money(40)
      . "  ████████████\nShop            "
      . \Civi::format()->money(10)
      . '  ███',
      BarsModifier::apply(self::ROWS, 'sum', 'form', 'money'),
    );
  }

  public function testEachNameColumnLinesUpOnItsOwn(): void {
    $rows = [
      ['type' => 'Contribution', 'status' => 'Completed', 'n' => 4],
      ['type' => 'Payment', 'status' => 'Completed', 'n' => 1],
      ['type' => 'Phone Call', 'status' => 'Scheduled', 'n' => 1],
    ];
    self::assertSame(
      "Contribution   Completed   4  ████████████\n"
      . "Payment        Completed   1  ███\n"
      . 'Phone Call     Scheduled   1  ███',
      BarsModifier::apply($rows, 'n', 'type,status'),
    );
  }

  public function testSeveralColumnsNameABar(): void {
    self::assertStringStartsWith(
      'Spring appeal   Donation   40  ████████████',
      BarsModifier::apply(self::ROWS, 'sum', 'form, type'),
    );
  }

  public function testAnEscapedLabelTakesTheWidthOfItsText(): void {
    $rows = [['form' => 'A &amp; B', 'sum' => 2], ['form' => 'ABC', 'sum' => 1]];

    self::assertSame(
      "A &amp; B   2  ████████████\nABC     1  ██████",
      BarsModifier::apply($rows, 'sum', 'form'),
    );
  }

  public function testNothingToChartIsNoData(): void {
    self::assertSame('(no data)', BarsModifier::apply([], 'sum', 'form'));
    self::assertSame('(no data)', BarsModifier::apply([['form' => 'Shop', 'sum' => 0]], 'sum', 'form'));
  }

  /**
   * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: string, 4?: string}>
   */
  public static function misuses(): array {
    return [
      'not rows' => ['12', 'sum', 'form', 'herald_bars needs the rows of a source and a column'],
      'no labels' => [self::ROWS, 'sum', '', 'herald_bars needs the columns that name each bar'],
      'unknown label' => [self::ROWS, 'sum', 'form,formular', 'the rows have no column "formular".'],
      'text value' => [self::ROWS, 'form', 'type', 'the column "form" holds a value that is not a number.'],
      'unknown format' => [self::ROWS, 'sum', 'form', 'the format must be "money" or left out.', 'euro'],
    ];
  }

  /**
   * @dataProvider misuses
   */
  public function testAMisuseIsAProblem(
    mixed $rows,
    mixed $column,
    mixed $labels,
    string $message,
    string $format = '',
  ): void {
    $this->expectException(DigestProblem::class);
    $this->expectExceptionMessage($message);
    BarsModifier::apply($rows, $column, $labels, $format);
  }

}
