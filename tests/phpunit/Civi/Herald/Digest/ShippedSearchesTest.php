<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class ShippedSearchesTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testTheFormOffersEveryShippedDisplay(): void {
    $offered = array_column(ShippedSearches::available(), 'id');
    $shipped = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addWhere('saved_search_id.name', 'LIKE', 'Herald\_Digest\_%')
      ->execute()
      ->column('id');
    sort($offered);
    sort($shipped);
    self::assertSame($shipped, $offered);
    self::assertCount(50, $offered);
  }

  public function testEveryFigureHasALabelAndAPeriodOnlyWhereItsNameHasOne(): void {
    self::assertSame(array_keys(ShippedSearches::FIGURES), array_keys(ShippedSearches::figureLabels()));
    self::assertSame(array_keys(ShippedSearches::PERIODS), array_keys(ShippedSearches::periodLabels()));
    foreach (ShippedSearches::available() as $entry) {
      self::assertSame(
        str_contains(ShippedSearches::FIGURES[$entry['figure']], '%s'),
        $entry['period'] !== NULL,
        $entry['figure'],
      );
    }
  }

}
