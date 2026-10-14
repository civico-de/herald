<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use PHPUnit\Framework\TestCase;

/**
 * Blocks of every type composed into one body, against the shipped templates.
 *
 * @group headless
 */
class ComposerTest extends TestCase implements HeadlessInterface, HookInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  /**
   * @param array<string> $keys
   * @param array<string, mixed> $context
   * @param array<string, mixed> $sections
   */
  public function hook_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
    $sections['test.seats'] = '🪑 <b>Seats</b> ' . $context['period'];
    $sections['test.unasked'] = 'never shown';
  }

  private function composer(): Composer {
    $stats = new class extends ContributionStatsService {

      public function getPeriodSummary(array $filters, DateRange $period): array {
        return ['total' => 120.0, 'count' => 3, 'bySource' => [['source' => 'Web', 'total' => 120.0, 'count' => 3]]];
      }

      public function getAllPending(array $filters): array {
        return ['total' => 0.0, 'count' => 0];
      }

    };
    $search = static fn (int $id): array => [
      'rows' => [
        SearchBlock::row(['type' => 'Gold', 'n' => 2], []),
        SearchBlock::row(['type' => 'Silver', 'n' => 5], []),
      ],
      'count' => 2,
    ];
    return new Composer($stats, new TemplateRenderer(), $search);
  }

  /**
   * @param list<array<string, mixed>> $blocks
   * @return array{name: string, header: string, period: string, blocks: list<array<string, mixed>>}
   */
  private static function digest(array $blocks, string $header = '', string $period = 'yesterday'): array {
    return ['name' => 'test_digest', 'header' => $header, 'period' => $period, 'blocks' => $blocks];
  }

  public function testBlocksOfEveryTypeComposeInOrder(): void {
    $digest = $this->composer()->compose(self::digest(
      [
        ['type' => 'contribution', 'title' => 'Donations', 'show_histogram' => FALSE],
        ['type' => 'search', 'saved_search_id' => 7, 'title' => 'Members'],
        ['type' => 'section', 'section_key' => 'test.seats', 'period' => 'yesterday'],
      ],
      '📊 <b>Daily</b>',
      'today',
    ));

    $parts = explode("\n\n", $digest['body']);
    self::assertCount(4, $parts);
    self::assertSame('📊 <b>Daily</b>', $parts[0]);
    self::assertStringContainsString('<b>Donations</b>', $parts[1]);
    self::assertStringContainsString('Web', $parts[1]);
    self::assertSame("<b>Members</b>\n• Gold · 2\n• Silver · 5", $parts[2]);
    self::assertSame('🪑 <b>Seats</b> yesterday', $parts[3]);
    self::assertNull($digest['subject']);
    self::assertSame(3, $digest['blocks']);
    self::assertSame(5, $digest['count']);
  }

  public function testTheSearchRunnerGetsTheSavedSearchId(): void {
    $asked = [];
    $composer = new Composer(
      new ContributionStatsService(),
      new TemplateRenderer(),
      static function (int $id) use (&$asked): array {
        $asked[] = $id;
        return ['rows' => [], 'count' => 0];
      },
    );
    $composer->compose(self::digest([['type' => 'search', 'saved_search_id' => '12']]));
    self::assertSame([12], $asked);
  }

  public function testOneBlockGivesTheMessageItsSubject(): void {
    $digest = $this->composer()->compose(self::digest([
      ['type' => 'contribution', 'template' => 'herald_contribution_digest_daily', 'show_histogram' => FALSE],
    ]));
    self::assertSame(1, $digest['blocks']);
    self::assertNotSame('', $digest['body']);
    self::assertMatchesRegularExpression('/ — 3 .+120/u', (string) $digest['subject']);
  }

  public function testAnInputEncodedHeaderIsHtmlAgain(): void {
    $digest = $this->composer()->compose(self::digest(
      [['type' => 'section', 'section_key' => 'test.seats']],
      '&lt;b&gt;LiMA&lt;/b&gt;',
    ));
    self::assertStringStartsWith("<b>LiMA</b>\n\n", $digest['body']);
  }

  public function testSearchRowsAreEscaped(): void {
    $composer = new Composer(
      new ContributionStatsService(),
      new TemplateRenderer(),
      static fn (): array => ['rows' => [SearchBlock::row(['label' => '<script>x</script>'], [])], 'count' => 1],
    );
    $digest = $composer->compose(self::digest([['type' => 'search', 'saved_search_id' => 1]]));
    self::assertStringNotContainsString('<script>', $digest['body']);
    self::assertStringContainsString('&lt;script&gt;', $digest['body']);
  }

  /**
   * @return array<string, array{list<array<string, mixed>>, string}>
   */
  public static function unsendableDigests(): array {
    return [
      'no blocks' => [[], 'Digest "test_digest" has no blocks.'],
      'unknown type' => [[['type' => 'chart']], 'Block 1: unknown type "chart".'],
      'search deleted since' => [
        [['type' => 'section', 'section_key' => 'test.seats'], ['type' => 'search']],
        'Block 2: a saved search block needs a saved search.',
      ],
    ];
  }

  /**
   * @dataProvider unsendableDigests
   * @param list<array<string, mixed>> $blocks
   */
  public function testAnUnsendableDigestFailsWithTheReason(array $blocks, string $message): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage($message);
    $this->composer()->compose(self::digest($blocks));
  }

}
