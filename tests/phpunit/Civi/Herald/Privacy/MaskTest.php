<?php

declare(strict_types = 1);

namespace Civi\Herald\Privacy;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests — no Civi headless needed.
 * @group unit
 */
class MaskTest extends TestCase {

  public function testWordsMaskPerCharacterKeepingFirstTwo(): void {
    $this->assertSame('Jo****** Fi****', Mask::words('Johannes Filter'));
    $this->assertSame('Al', Mask::words('Al'));
    $this->assertSame('', Mask::words(''));
  }

  public function testUmlautsCountAsSingleCharacters(): void {
    $this->assertSame('Jü****', Mask::words('Jürgen'));
  }

  public function testEmailMasksBothSidesKeepingTld(): void {
    $this->assertSame('jo******@ex*****.org', Mask::email('johannes@example.org'));
    $this->assertSame('hi@jf*****.de', Mask::email('hi@jfilter.de'));
    $this->assertSame('jo**@ma**.gm*.net', Mask::email('jojo@mail.gmx.net'));
    $this->assertSame('', Mask::email(''));
  }

  public function testEmailWithoutAtFallsBackToWordMasking(): void {
    $this->assertSame('ke*******', Mask::email('keinemail'));
  }

}
