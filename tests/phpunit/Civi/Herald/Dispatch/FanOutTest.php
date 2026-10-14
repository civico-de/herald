<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * Address-list parsing of the fan-out.
 * @group unit
 */
class FanOutTest extends TestCase {

  public function testAddressListSplitsAndDeduplicates(): void {
    $this->assertSame(
      ['ops@example.org', 'oncall@example.org', 'third@example.org'],
      FanOut::addressList("ops@example.org, oncall@example.org;\nops@example.org\nthird@example.org"),
    );
  }

  public function testAddressListHandlesEmptyAndNonScalar(): void {
    $this->assertSame([], FanOut::addressList(''));
    $this->assertSame([], FanOut::addressList(NULL));
    $this->assertSame(['a@example.org'], FanOut::addressList(['a@example.org', '']));
  }

}
