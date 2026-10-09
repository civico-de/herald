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
    $search = static fn (string $name): array => [
      'rows' => [
        SearchBlock::row(['type' => 'Gold', 'n' => 2], []),
        SearchBlock::row(['type' => 'Silver', 'n' => 5], []),
      ],
      'count' => 2,
    ];
    return new Composer($stats, new TemplateRenderer(), $search);
  }

  public function testBlocksOfEveryTypeComposeInOrder(): void {
    $digest = $this->composer()->compose([
      'header' => '📊 <b>Daily</b>',
      'period' => 'today',
      'blocks' => json_encode([
        ['title' => 'Donations', 'show_histogram' => FALSE],
        ['type' => 'search', 'search' => 'memberships', 'title' => 'Members'],
        ['type' => 'section', 'key' => 'test.seats', 'period' => 'yesterday'],
      ]),
    ]);

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

  public function testWithoutBlocksTheParamsAreOneBlockWithItsSubject(): void {
    $digest = $this->composer()->compose([
      'template' => 'herald_contribution_digest_daily',
      'show_histogram' => FALSE,
    ]);
    self::assertSame(1, $digest['blocks']);
    self::assertNotSame('', $digest['body']);
    self::assertMatchesRegularExpression('/ — 3 .+120/u', (string) $digest['subject']);
  }

  public function testSearchRowsAreEscaped(): void {
    $composer = new Composer(
      new ContributionStatsService(),
      new TemplateRenderer(),
      static fn (): array => ['rows' => [SearchBlock::row(['label' => '<script>x</script>'], [])], 'count' => 1],
    );
    $digest = $composer->compose(['blocks' => [['type' => 'search', 'search' => 'x']]]);
    self::assertStringNotContainsString('<script>', $digest['body']);
    self::assertStringContainsString('&lt;script&gt;', $digest['body']);
  }

  public function testUnknownTypeFails(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->composer()->compose(['blocks' => [['type' => 'chart']]]);
  }

}
