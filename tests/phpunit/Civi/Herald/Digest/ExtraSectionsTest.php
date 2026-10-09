<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class ExtraSectionsTest extends TestCase {

  public function testParseKeysFromCommaSeparatedString(): void {
    $this->assertSame(['a.b', 'c.d'], ExtraSections::parseKeys(' a.b , c.d '));
  }

  public function testParseKeysFromJsonArray(): void {
    $this->assertSame(['a.b', 'c.d'], ExtraSections::parseKeys('["a.b", "c.d"]'));
  }

  public function testParseKeysDropsEmptyAndDuplicateEntries(): void {
    $this->assertSame(['a.b'], ExtraSections::parseKeys('a.b, , a.b'));
    $this->assertSame([], ExtraSections::parseKeys(''));
    $this->assertSame([], ExtraSections::parseKeys(NULL));
  }

  public function testParseKeysSkipsNonScalarEntries(): void {
    $this->assertSame(['a.b'], ExtraSections::parseKeys(['a.b', ['nested'], NULL]));
  }

}
