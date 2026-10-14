<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Afform;
use Civi\Api4\HeraldDigest;
use Civi\Api4\HeraldDigestBlock;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Digests and blocks that could not be sent are refused on save, through
 * the API and through the digest form.
 *
 * @group headless
 */
class ValidatorTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  private function digest(string $name): int {
    return (int) HeraldDigest::create(checkPermissions: FALSE)
      ->setValues(['name' => $name, 'title' => ucfirst($name)])
      ->execute()
      ->single()['id'];
  }

  private function assertRefused(string $message, callable $save): void {
    try {
      $save();
    }
    catch (\CRM_Core_Exception $e) {
      self::assertStringContainsString($message, $e->getMessage());
      return;
    }
    self::fail("Saved although: {$message}");
  }

  public function testAHealthyDigestAndItsBlocksAreSaved(): void {
    $id = $this->digest('weekly');
    HeraldDigest::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $id)
      ->addValue('name', 'weekly')
      ->addValue('period', 'today')
      ->execute();
    $search = \Civi\Api4\SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_members',
        'label' => 'Members',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['id']],
      ])
      ->execute()
      ->single()['id'];
    $display = \Civi\Api4\SearchDisplay::create(checkPermissions: FALSE)
      ->setValues(['saved_search_id' => $search, 'label' => 'Members', 'type' => 'table', 'settings' => []])
      ->execute()
      ->single()['id'];
    $block = (int) HeraldDigestBlock::create(checkPermissions: FALSE)
      ->setValues(['digest_id' => $id, 'type' => 'search', 'search_display_id' => $display])
      ->execute()
      ->single()['id'];
    HeraldDigestBlock::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $block)
      ->addValue('title', 'Members')
      ->execute();
    self::assertSame(
      1,
      HeraldDigestBlock::get(checkPermissions: FALSE)->addWhere('digest_id', '=', $id)->execute()->count(),
    );
  }

  /**
   * @return array<string, array{array<string, mixed>, string}>
   */
  public static function brokenDigests(): array {
    return [
      'name with spaces' => [['name' => 'Weekly report'], 'Use lowercase letters'],
      'unknown period' => [['name' => 'weekly', 'period' => 'last_year'], 'Unknown period "last_year"'],
    ];
  }

  /**
   * @dataProvider brokenDigests
   * @param array<string, mixed> $values
   */
  public function testABrokenDigestIsRefused(array $values, string $message): void {
    $this->assertRefused($message, static fn () => HeraldDigest::create(checkPermissions: FALSE)
      ->setValues($values + ['title' => 'Weekly'])
      ->execute());
  }

  public function testANameIsUsedByOneDigestOnly(): void {
    $this->digest('weekly');
    $other = $this->digest('daily');
    $this->assertRefused(
      'Another digest is already named "weekly"',
      static fn () => HeraldDigest::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $other)
        ->addValue('name', 'weekly')
        ->execute(),
    );
  }

  public function testABatchUpdateIsCheckedForEveryDigest(): void {
    $this->digest('weekly');
    $this->digest('daily');
    $this->assertRefused(
      'Another digest is already named "weekly"',
      static fn () => HeraldDigest::update(checkPermissions: FALSE)
        ->addWhere('name', 'IN', ['weekly', 'daily'])
        ->addValue('name', 'weekly')
        ->execute(),
    );
  }

  public function testAnUpdateIsCheckedTogetherWithTheStoredBlock(): void {
    $block = (int) HeraldDigestBlock::create(checkPermissions: FALSE)
      ->setValues(['digest_id' => $this->digest('weekly'), 'type' => 'section', 'section_key' => 'myext.seats'])
      ->execute()
      ->single()['id'];
    $this->assertRefused(
      'A search block needs a search display',
      static fn () => HeraldDigestBlock::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $block)
        ->addValue('type', 'search')
        ->execute(),
    );
  }

  /**
   * @param list<array<string, mixed>> $blocks
   * @param array<string, mixed> $args
   */
  private function submit(array $fields, array $blocks, array $args = []): void {
    // The form fills the schema defaults into every new block, shown or not.
    $withDefaults = static fn (array $block): array => $block + Options::angularSettings()['blockDefaults'];
    Afform::submit(checkPermissions: FALSE)
      ->setName('afformHeraldDigest')
      ->setArgs($args)
      ->setValues([
        'HeraldDigest1' => [[
          'fields' => $fields,
          'joins' => ['HeraldDigestBlock' => array_map($withDefaults, $blocks)],
        ]],
      ])
      ->execute();
  }

  public function testTheFormSavesADigestWithItsBlocks(): void {
    $this->submit(['name' => 'weekly', 'title' => 'Weekly', 'period' => 'yesterday'], [[
      'type' => 'section',
      'section_key' => 'myext.seats',
    ]]);
    $id = HeraldDigest::get(checkPermissions: FALSE)->addWhere('name', '=', 'weekly')->execute()->single()['id'];
    self::assertSame(
      ['myext.seats'],
      HeraldDigestBlock::get(checkPermissions: FALSE)
        ->addWhere('digest_id', '=', $id)
        ->execute()
        ->column('section_key'),
    );
  }

  public function testTheFormRefusesABrokenBlockBeforeWritingTheDigest(): void {
    $this->assertRefused('Block 2: a search block needs a search display.', fn () => $this->submit(
      ['name' => 'weekly', 'title' => 'Weekly', 'period' => 'yesterday'],
      [['type' => 'contribution'], ['type' => 'search']],
    ));
    self::assertSame(
      0,
      HeraldDigest::get(checkPermissions: FALSE)->addWhere('name', '=', 'weekly')->execute()->count(),
    );
  }

  public function testTheFormKeepsTheNameOfTheDigestItEdits(): void {
    $id = $this->digest('weekly');
    $this->submit(
      ['name' => 'weekly', 'title' => 'Renamed', 'period' => 'today'],
      [['type' => 'contribution']],
      ['HeraldDigest1' => $id],
    );
    self::assertSame(
      'Renamed',
      HeraldDigest::get(checkPermissions: FALSE)->addWhere('id', '=', $id)->execute()->single()['title'],
    );
    $this->assertRefused(
      'Another digest is already named "weekly"',
      fn () => $this->submit(['name' => 'weekly', 'title' => 'Copy', 'period' => 'today'], [[
        'type' => 'contribution',
      ]]),
    );
  }

}
