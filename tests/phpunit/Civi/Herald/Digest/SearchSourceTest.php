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
 * A search display runs as the system and yields the raw values of its
 * columns, named after their headers.
 *
 * @group headless
 */
class SearchSourceTest extends TestCase implements HeadlessInterface, TransactionalInterface {

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
  private function display(array $apiParams, array $columns, array $settings = [], string $type = 'table'): int {
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
        'type' => $type,
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
        ['type' => 'field', 'key' => 'n', 'label' => 'Kontakte gesamt'],
      ],
      ['sort' => [['n', 'DESC']]],
    );

    self::assertSame(
      [
        'type' => 'Organization',
        'kontakte_gesamt' => 3,
        'rows' => [
          ['type' => 'Organization', 'kontakte_gesamt' => 3],
          ['type' => 'Household', 'kontakte_gesamt' => 1],
        ],
      ],
      SearchSource::values($id),
    );
    self::assertSame(
      [
        'fields' => [
          ['name' => 'type', 'label' => 'Type', 'type' => 'String'],
          ['name' => 'kontakte_gesamt', 'label' => 'Kontakte gesamt', 'type' => 'Integer'],
        ],
        'single' => FALSE,
      ],
      SearchSource::placeholders($id),
    );
  }

  public function testAnAggregateWithoutGroupingIsOneRow(): void {
    $id = $this->display(
      ['select' => ['COUNT(id) AS n', 'MAX(created_date) AS newest']],
      [
        ['type' => 'field', 'key' => 'n', 'label' => 'Contacts'],
        ['type' => 'field', 'key' => 'newest', 'label' => 'Newest'],
      ],
    );

    self::assertSame(
      [
        'fields' => [
          ['name' => 'contacts', 'label' => 'Contacts', 'type' => 'Integer'],
          ['name' => 'newest', 'label' => 'Newest', 'type' => 'Timestamp'],
        ],
        'single' => TRUE,
      ],
      SearchSource::placeholders($id),
    );
  }

  public function testTheDisplaysLimitAndSortApply(): void {
    $id = $this->display(
      ['select' => ['display_name'], 'orderBy' => ['display_name' => 'ASC']],
      [['type' => 'field', 'key' => 'display_name', 'label' => 'Name']],
      ['sort' => [['display_name', 'DESC']], 'limit' => 2],
    );

    self::assertSame(
      [['name' => 'Herald Test H'], ['name' => 'Herald Test C']],
      SearchSource::values($id)['rows'],
    );
  }

  public function testColumnsWithoutValueOrHeaderHaveNoName(): void {
    $id = $this->display(
      ['select' => ['display_name', 'contact_type'], 'where' => [['display_name', '=', 'Herald Test A']]],
      [
        ['type' => 'field', 'key' => 'display_name', 'label' => 'Name'],
        ['type' => 'field', 'key' => 'contact_type', 'label' => ''],
        ['type' => 'links', 'label' => 'Links', 'links' => []],
      ],
    );

    self::assertSame(['name' => 'Herald Test A', 'rows' => [['name' => 'Herald Test A']]], SearchSource::values($id));
    self::assertSame(
      ['fields' => [['name' => 'name', 'label' => 'Name', 'type' => 'String']], 'single' => FALSE],
      SearchSource::placeholders($id),
    );
  }

  public function testTheDisplayRunsWithoutTheUsersPermissions(): void {
    $id = $this->display(
      ['select' => ['COUNT(id) AS n']],
      [['type' => 'field', 'key' => 'n', 'label' => 'Contacts']],
    );
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = [];

    self::assertSame(4, SearchSource::values($id)['contacts']);
  }

  public function testAnAutoColumnDisplayNamesTheSearchsColumns(): void {
    $id = $this->display(
      ['select' => ['display_name'], 'where' => [['display_name', '=', 'Herald Test A']]],
      [],
      ['columnMode' => 'auto'],
    );

    self::assertSame('Herald Test A', SearchSource::values($id)['display_name']);
  }

  /**
   * A rewrite formats the display's cell; the template formats the value itself.
   */
  public function testValuesAreRawNotRewritten(): void {
    $id = $this->display(
      ['select' => ['display_name'], 'where' => [['display_name', '=', 'Herald Test A']]],
      [
        ['type' => 'html', 'key' => 'display_name', 'label' => 'Name', 'rewrite' => '<b>[display_name]</b> & co'],
        ['type' => 'field', 'key' => 'display_name', 'label' => 'Again', 'rewrite' => 'again'],
      ],
    );

    self::assertSame(['name' => 'Herald Test A', 'again' => 'Herald Test A'], SearchSource::values($id)['rows'][0]);
  }

  /**
   * Smarty computes with NULL, an empty string is a TypeError in {$x * 100}.
   */
  public function testAnEmptyValueIsNull(): void {
    $id = $this->display(
      ['select' => ['CONCAT_WS(" ", first_name) AS first'], 'where' => [['display_name', '=', 'Herald Test A']]],
      [['type' => 'field', 'key' => 'first', 'label' => 'First']],
    );

    self::assertSame(['first' => NULL, 'rows' => [['first' => NULL]]], SearchSource::values($id));
  }

  public function testATotalOfNoRowsIsZero(): void {
    $id = $this->display(
      [
        'select' => ['SUM(id) AS SUM_id', 'COUNT(id) AS n', 'MAX(display_name) AS last'],
        'where' => [['display_name', '=', 'Nobody']],
      ],
      [
        ['type' => 'field', 'key' => 'SUM_id', 'label' => 'Sum'],
        ['type' => 'field', 'key' => 'n', 'label' => 'Count'],
        ['type' => 'field', 'key' => 'last', 'label' => 'Last'],
      ],
    );

    self::assertSame(
      ['sum' => 0, 'count' => 0, 'last' => NULL, 'rows' => [['sum' => 0, 'count' => 0, 'last' => NULL]]],
      SearchSource::values($id),
    );
  }

  public function testWithoutRowsEveryNameIsNull(): void {
    $id = $this->display(
      ['select' => ['display_name', 'contact_type'], 'where' => [['display_name', '=', 'Nobody']]],
      [
        ['type' => 'field', 'key' => 'display_name', 'label' => 'Name'],
        ['type' => 'field', 'key' => 'contact_type', 'label' => 'Type'],
      ],
    );

    self::assertSame(['name' => NULL, 'type' => NULL, 'rows' => []], SearchSource::values($id));
  }

  public function testHeadersThatGiveNoUsableNameAreProblems(): void {
    $columns = static fn (string ...$labels): array => array_map(
      static fn (string $label): array => ['type' => 'field', 'key' => 'display_name', 'label' => $label],
      $labels,
    );
    $twice = $this->display(['select' => ['display_name']], $columns('Summe', 'summe!'));
    $rows = $this->display(['select' => ['display_name']], $columns('Rows'));
    $digit = $this->display(['select' => ['display_name']], $columns('1. Quartal'));

    self::assertSame('two columns are named summe; give their headers different words.', SearchSource::problem($twice));
    self::assertSame(
      'the column "Rows" is named rows, which holds the list of rows; rename its header.',
      SearchSource::problem($rows),
    );
    self::assertSame(
      'the column "1. Quartal" starts with a digit; start its header with a letter.',
      SearchSource::problem($digit),
    );
    $this->expectExceptionMessage('two columns are named summe');
    SearchSource::values($twice);
  }

  public function testOnlyDisplaysWithRowsCanBeChosen(): void {
    $table = $this->display(['select' => ['display_name']], []);
    $autocomplete = $this->display(['select' => ['display_name']], [], [], 'autocomplete');

    self::assertNull(SearchSource::problem($table));
    self::assertSame(
      'a display of type "autocomplete" has no rows for a digest; use a table, list or grid.',
      SearchSource::problem($autocomplete),
    );
    self::assertSame('search display 999999 does not exist.', SearchSource::problem(999_999));
  }

  public function testMissingDisplayFails(): void {
    $this->expectException(DigestProblem::class);
    $this->expectExceptionMessage('search display 999999 does not exist');
    SearchSource::values(999_999);
  }

}
