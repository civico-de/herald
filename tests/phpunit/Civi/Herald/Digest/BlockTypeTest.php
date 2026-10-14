<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * What a block needs before it is saved or sent.
 *
 * @group headless
 */
class BlockTypeTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function testAHealthyBlockOfEveryTypeHasNoProblem(): void {
    self::assertNull(BlockType::problem([]));
    self::assertNull(BlockType::problem([
      'type' => 'contribution',
      'period' => 'last_7_days',
      'group_by' => 'line_item',
      'bucket_count' => 1,
      'bucket_edges' => '[0, 10.5, 100]',
    ]));
    // Edges of a block without histogram are never used.
    self::assertNull(BlockType::problem(['show_histogram' => FALSE, 'bucket_edges' => '[5]']));
    self::assertNull(BlockType::problem(['type' => 'search', 'search_display_id' => 3]));
    self::assertNull(BlockType::problem(['type' => 'section', 'section_key' => 'myext.seats']));
  }

  /**
   * @return array<string, array{array<string, mixed>, string}>
   */
  public static function brokenBlocks(): array {
    return [
      'unknown type' => [['type' => 'chart'], 'unknown type "chart"'],
      'search without search' => [['type' => 'search'], 'needs a search display'],
      'section with blank key' => [['type' => 'section', 'section_key' => ' '], 'needs a section key'],
      'unknown period' => [
        ['type' => 'search', 'search_display_id' => 3, 'period' => 'lastyear'],
        'unknown period "lastyear"',
      ],
      'unknown grouping' => [['group_by' => 'campaign'], 'unknown grouping "campaign"'],
      'no buckets' => [['bucket_count' => 0], 'at least 1'],
      'edges not JSON' => [['bucket_edges' => '0, 10'], 'JSON array'],
      'one edge' => [['bucket_edges' => '[5]'], 'JSON array'],
      'empty edges' => [['bucket_edges' => '[]'], 'JSON array'],
      'edges out of order' => [['bucket_edges' => '[0, 50, 10]'], 'ascending'],
      'edge not a number' => [['bucket_edges' => '[0, "10"]'], 'ascending'],
    ];
  }

  /**
   * @dataProvider brokenBlocks
   * @param array<string, mixed> $block
   */
  public function testABrokenBlockIsNamed(array $block, string $expected): void {
    self::assertStringContainsString($expected, (string) BlockType::problem($block));
  }

  public function testEdgesAreFloatsOrNull(): void {
    self::assertNull(BucketEdges::parse(NULL));
    self::assertNull(BucketEdges::parse(' '));
    self::assertSame([0.0, 10.0, 50.5], BucketEdges::parse('[0, 10, 50.5]'));
    self::assertSame([1.0, 2.0], BucketEdges::parse([1, 2]));
  }

}
