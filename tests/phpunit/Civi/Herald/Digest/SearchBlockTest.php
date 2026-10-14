<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Contact;
use Civi\Api4\SavedSearch;
use Civi\Api4\SearchDisplay;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A search display runs as the system and yields its formatted value columns.
 *
 * @group headless
 */
class SearchBlockTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    foreach (['Herald Test A', 'Herald Test B', 'Herald Test C'] as $name) {
      Contact::create(checkPermissions: FALSE)
        ->setValues(['contact_type' => 'Organization', 'organization_name' => $name])
        ->execute();
    }
    Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Household', 'household_name' => 'Herald Test H'])
      ->execute();
  }

  /**
   * @param array<string, mixed> $apiParams
   * @param list<array<string, mixed>> $columns
   * @param array<string, mixed> $settings
   */
  private function display(array $apiParams, array $columns, array $settings = []): int {
    $search = SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'label' => 'Herald test search',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4] + $apiParams + ['where' => [['display_name', 'LIKE', 'Herald Test%']]],
      ])
      ->execute()
      ->single();
    return SearchDisplay::create(checkPermissions: FALSE)
      ->setValues([
        'saved_search_id' => $search['id'],
        'label' => 'Herald test display',
        'type' => 'table',
        'settings' => ['columns' => $columns, 'pager' => FALSE] + $settings,
      ])
      ->execute()
      ->single()['id'];
  }

  public function testGroupedDisplayGivesOneRowPerGroup(): void {
    $id = $this->display(
      ['select' => ['contact_type:label', 'COUNT(id) AS n'], 'groupBy' => ['contact_type']],
      [
        ['type' => 'field', 'key' => 'contact_type:label', 'label' => 'Type'],
        ['type' => 'field', 'key' => 'n', 'label' => 'Contacts'],
      ],
      ['sort' => [['n', 'DESC']]],
    );

    $vars = SearchBlock::vars($id);

    self::assertSame(['Type', 'Contacts'], $vars['labels']);
    self::assertSame(2, $vars['count']);
    self::assertSame(
      [
        ['cells' => ['Organization', '3'], 'values' => ['contact_type_label' => 'Organization', 'n' => '3']],
        ['cells' => ['Household', '1'], 'values' => ['contact_type_label' => 'Household', 'n' => '1']],
      ],
      $vars['rows'],
    );
  }

  public function testTheDisplaysLimitAndSortApply(): void {
    $id = $this->display(
      ['select' => ['display_name'], 'orderBy' => ['display_name' => 'ASC']],
      [['type' => 'field', 'key' => 'display_name', 'label' => 'Name']],
      ['sort' => [['display_name', 'DESC']], 'limit' => 2],
    );

    self::assertSame(
      [['Herald Test H'], ['Herald Test C']],
      array_column(SearchBlock::vars($id)['rows'], 'cells'),
    );
  }

  public function testOnlyValueColumnsCount(): void {
    $id = $this->display(
      ['select' => ['display_name'], 'where' => [['display_name', '=', 'Herald Test A']]],
      [
        ['type' => 'field', 'key' => 'display_name', 'label' => 'Name'],
        ['type' => 'links', 'label' => 'Links', 'links' => []],
      ],
    );

    $vars = SearchBlock::vars($id);
    self::assertSame(['Name'], $vars['labels']);
    self::assertSame([['cells' => ['Herald Test A'], 'values' => ['display_name' => 'Herald Test A']]], $vars['rows']);
  }

  public function testTheDisplayRunsWithoutTheUsersPermissions(): void {
    $id = $this->display(
      ['select' => ['COUNT(id) AS n']],
      [['type' => 'field', 'key' => 'n', 'label' => 'Contacts']],
    );
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = [];

    self::assertSame([['4']], array_column(SearchBlock::vars($id)['rows'], 'cells'));
  }

  public function testMissingDisplayFails(): void {
    $this->expectExceptionMessage('search display 999999 does not exist');
    SearchBlock::vars(999_999);
  }

}
