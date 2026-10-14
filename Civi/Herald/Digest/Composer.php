<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Assembles a stored digest (Definition::load) into one body.
 *
 * Block types (`type`, default "contribution"):
 *   contribution — DigestVars filters, template herald_contribution_digest_block
 *   search       — `search_display_id`, template herald_digest_search_block
 *   section      — `section_key`: a section from hook_civicrm_heraldDigestSections, inserted as HTML
 */
final class Composer {

  public const DEFAULT_TEMPLATES = [
    'contribution' => 'herald_contribution_digest_block',
    'search' => 'herald_digest_search_block',
  ];

  /**
   * @var \Closure(int): array<string, mixed>
   */
  private \Closure $searchRunner;

  /**
   * @param (callable(int): array<string, mixed>)|null $searchRunner SearchDisplay id → block variables
   */
  public function __construct(
    private readonly ContributionStatsService $stats = new ContributionStatsService(),
    private readonly TemplateRenderer $renderer = new TemplateRenderer(),
    ?callable $searchRunner = NULL,
  ) {
    $this->searchRunner = $searchRunner === NULL ? SearchBlock::vars(...) : $searchRunner(...);
  }

  /**
   * @param array{name: string, header: string, period: string, blocks: list<array<string, mixed>>} $digest
   * @return array{body: string, subject: ?string, blocks: int, count: int}
   */
  public function compose(array $digest): array {
    $blocks = $digest['blocks'];
    // Blocks are checked on save; a display deleted since leaves its block without one.
    $problems = DigestRules::blockProblems($digest['name'], $blocks);
    if ($problems !== []) {
      throw new \CRM_Core_Exception(implode(' ', $problems));
    }
    // The header is HTML; core's input encoding may have stored it encoded.
    $parts = [trim(TemplateRenderer::decodeInput($digest['header']))];
    $subject = NULL;
    $count = 0;
    foreach ($blocks as $block) {
      $rendered = $this->renderBlock($block + ['period' => $digest['period']], $digest['name']);
      $parts[] = $rendered['body'];
      $subject ??= $rendered['subject'];
      $count += $rendered['count'];
    }
    return [
      'body' => implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== '')),
      // One block's subject names the whole message; several need email_subject.
      'subject' => count($blocks) === 1 ? $subject : NULL,
      'blocks' => count($blocks),
      'count' => $count,
    ];
  }

  /**
   * @param array<string, mixed> $block
   * @return array{body: string, subject: ?string, count: int}
   */
  private function renderBlock(array $block, string $digestName): array {
    $type = Cast::toString($block['type'] ?? 'contribution');
    if ($type === 'section') {
      $context = ['digest' => $digestName, 'period' => $block['period']];
      return [
        'body' => ExtraSections::answer(Cast::toString($block['section_key']), $context),
        'subject' => NULL,
        'count' => 0,
      ];
    }
    $vars = match ($type) {
      'contribution' => DigestVars::build($block, $this->stats),
      'search' => ($this->searchRunner)(Cast::toInt($block['search_display_id'])),
      default => throw new \CRM_Core_Exception("herald: unknown digest block type \"{$type}\""),
    };
    $vars['block_title'] = Cast::toString($block['title'] ?? '');
    $template = Cast::toString($block['template'] ?? '');
    $totals = $vars['totals'] ?? NULL;
    $parts = $this->renderer->renderParts($template !== '' ? $template : self::DEFAULT_TEMPLATES[$type], $vars);
    return [
      'body' => trim($parts['body']),
      'subject' => $parts['subject'],
      'count' => Cast::toInt((is_array($totals) ? $totals['count'] ?? NULL : NULL) ?? $vars['count'] ?? 0),
    ];
  }

}
