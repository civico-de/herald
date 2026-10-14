<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class NiceNumberTest extends TestCase {

  public function testLabelsDropTrailingZerosAndShortenThousands(): void {
    $labels = array_map(NiceNumber::label(...), [
      0.0,
      0.5,
      20.0,
      19.9999999999,
      999.99,
      1000.0,
      1500.0,
      10000.0,
      2_000_000.0,
    ]);
    $this->assertSame(['0', '0.5', '20', '20', '999.99', '1k', '1.5k', '10k', '2000k'], $labels);
  }

}
