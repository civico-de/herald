<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the watch-list entity normalisation.
 *
 * @group unit
 */
class FieldTransitionWatchListTest extends TestCase {

  public function testContactSubtypesFoldToContact(): void {
    $this->assertSame('contact', FieldTransitionWatchList::normalizeEntity('Individual'));
    $this->assertSame('contact', FieldTransitionWatchList::normalizeEntity('Organization'));
    $this->assertSame('contact', FieldTransitionWatchList::normalizeEntity('Household'));
    $this->assertSame('contact', FieldTransitionWatchList::normalizeEntity('Contact'));
  }

  public function testMatchIsCaseInsensitive(): void {
    $this->assertSame('contribution', FieldTransitionWatchList::normalizeEntity('Contribution'));
    $this->assertSame('contribution', FieldTransitionWatchList::normalizeEntity('contribution'));
    $this->assertSame('contribution', FieldTransitionWatchList::normalizeEntity(' CONTRIBUTION '));
  }

}
