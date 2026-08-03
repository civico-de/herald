<?php

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

  public function testOrderFollowsRequestedKeysNotContributionOrder(): void {
    $sections = ['second' => 'B', 'first' => 'A'];
    $this->assertSame(['A', 'B'], ExtraSections::order($sections, ['first', 'second']));
  }

  public function testOrderIgnoresUnrequestedAndEmptySections(): void {
    $sections = ['wanted' => ' A ', 'unwanted' => 'B', 'blank' => "  \n "];
    $this->assertSame(['A'], ExtraSections::order($sections, ['wanted', 'blank', 'missing']));
  }

  public function testCollectWithoutKeysNeverDispatches(): void {
    // No dispatcher available in a unit test — reaching Civi::dispatcher()
    // would fatal, so an empty key list must short-circuit before that.
    $this->assertSame([], ExtraSections::collect([], ['period' => 'yesterday']));
  }

}
