<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Contact;
use Civi\Api4\SavedSearch;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A saved search runs as the system and yields its own columns only.
 *
 * @group headless
 */
class SearchBlockTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  /**
   * @param array<string, mixed> $apiParams
   */
  private function savedSearch(string $name, array $apiParams): void {
    SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'name' => $name,
        'label' => $name,
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4] + $apiParams,
      ])
      ->execute();
  }

  public function testGroupedSearchGivesOneRowPerGroup(): void {
    foreach (['Herald Test A', 'Herald Test B'] as $name) {
      Contact::create(checkPermissions: FALSE)
        ->setValues(['contact_type' => 'Organization', 'organization_name' => $name])
        ->execute();
    }
    $this->savedSearch('herald_test_by_type', [
      'select' => ['contact_type:label', 'COUNT(id) AS n'],
      'where' => [['organization_name', 'LIKE', 'Herald Test%']],
      'groupBy' => ['contact_type'],
    ]);

    $vars = SearchBlock::vars('herald_test_by_type');

    self::assertSame(1, $vars['count']);
    self::assertSame(['Organization', '2'], $vars['rows'][0]['cells']);
    self::assertSame(['contact_type_label' => 'Organization', 'n' => '2'], $vars['rows'][0]['values']);
  }

  public function testUnaliasedAggregateKeepsItsCell(): void {
    Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Organization', 'organization_name' => 'Herald Test D'])
      ->execute();
    $this->savedSearch('herald_test_unaliased', [
      'select' => ['contact_type', 'COUNT(id)'],
      'where' => [['organization_name', '=', 'Herald Test D']],
      'groupBy' => ['contact_type'],
    ]);

    self::assertSame(
      [['cells' => ['Organization', '1'], 'values' => ['contact_type' => 'Organization', 'COUNT_id' => '1']]],
      SearchBlock::vars('herald_test_unaliased')['rows'],
    );
  }

  public function testUngroupedSearchLeavesTheAddedIdOut(): void {
    Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Organization', 'organization_name' => 'Herald Test C'])
      ->execute();
    $this->savedSearch('herald_test_names', [
      'select' => ['organization_name'],
      'where' => [['organization_name', '=', 'Herald Test C']],
    ]);

    self::assertSame(
      [['cells' => ['Herald Test C'], 'values' => ['organization_name' => 'Herald Test C']]],
      SearchBlock::vars('herald_test_names')['rows'],
    );
  }

  public function testMissingSearchFails(): void {
    self::assertStringContainsString('herald_test_missing', (string) SearchBlock::problem('herald_test_missing'));
    $this->expectException(\CRM_Core_Exception::class);
    SearchBlock::vars('herald_test_missing');
  }

  public function testColumnsFollowTheSelect(): void {
    self::assertSame(
      ['a:label', 'total', 'COUNT:id', 'n', 'p'],
      SearchBlock::columns(['a:label', 'SUM(x) AS total', 'COUNT(id)', 'COUNT(*) AS n', '(a * b) AS p']),
    );
    self::assertSame([], SearchBlock::columns(['*', 'a']));
    self::assertSame([], SearchBlock::columns(['a', 'email.*']));
    self::assertSame(
      ['cells' => ['', '1'], 'values' => ['b' => '', 'a' => '1']],
      SearchBlock::row(['id' => 9, 'a' => 1], ['b', 'a']),
    );
    self::assertSame(
      ['cells' => ['x, y'], 'values' => ['tags' => 'x, y']],
      SearchBlock::row(['tags' => ['x', 'y']], []),
    );
  }

}
