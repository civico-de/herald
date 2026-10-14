<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use PHPUnit\Framework\TestCase;

final class FloatTextTest extends TestCase {

  /**
   * @return array<string, array{float, string}>
   */
  public static function floats(): array {
    return [
      'amount' => [19.99, '19.99'],
      'whole amount' => [1000.0, '1000'],
      'sixteen digits' => [52.520_006_599_999_99, '52.52000659999999'],
      'seventeen digits' => [0.1 + 0.2, '0.30000000000000004'],
      'small' => [5.0E-5, '5.0E-5'],
      'large' => [1.0E+20, '1.0E+20'],
      'negative' => [-3.5, '-3.5'],
      'zero' => [0.0, '0'],
    ];
  }

  /**
   * @dataProvider floats
   */
  public function testTheTextIsShortAndReadsBackAsTheSameFloat(float $value, string $text): void {
    $this->assertSame($text, FloatText::of($value));
    $this->assertSame($value, (float) FloatText::of($value));
    $this->assertTrue(is_numeric(FloatText::of($value)));
  }

}
