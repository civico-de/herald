<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * A form names every unusable block before the rule is saved.
 *
 * @group headless
 */
class BlockListTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function testProblemsNameEveryBrokenBlock(): void {
    $problems = BlockList::problems([
      'blocks' => '[{"type": "chart"}, {"type": "search"}, {"type": "section", "key": "a"}, {"type": "search", "search": "nope"}]',
    ]);
    self::assertCount(3, $problems);
    self::assertStringContainsString('1', $problems[0]);
    self::assertStringContainsString('"search"', $problems[1]);
    self::assertStringContainsString('nope', $problems[2]);
    self::assertSame([], BlockList::problems(['blocks' => '']));
    self::assertCount(1, BlockList::problems(['blocks' => '{"type": "search"']));
  }

  public function testWithoutBlocksTheParamsAreTheBlock(): void {
    self::assertSame([['template' => 'x']], BlockList::parse(['template' => 'x']));
    self::assertSame([['template' => 'x', 'blocks' => '']], BlockList::parse(['template' => 'x', 'blocks' => '']));
  }

}
