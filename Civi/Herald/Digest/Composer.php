<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Util\Cast;

/**
 * Assembles a digest from its blocks (BlockList) into one body.
 *
 * Block types (`type`, default "contribution"):
 *   contribution — DigestVars filters, template herald_contribution_digest_block
 *   search       — `search`: SavedSearch name, template herald_digest_search_block
 *   section      — `key`: a section from hook_civicrm_heraldDigestSections, inserted as HTML
 */
final class Composer {

  public const DEFAULT_TEMPLATES = [
    'contribution' => 'herald_contribution_digest_block',
    'search' => 'herald_digest_search_block',
  ];

  /**
   * @var \Closure(string): array<string, mixed>
   */
  private \Closure $searchRunner;

  /**
   * @param (callable(string): array<string, mixed>)|null $searchRunner SavedSearch name → block variables
   */
  public function __construct(
    private readonly ContributionStatsService $stats = new ContributionStatsService(),
    private readonly TemplateRenderer $renderer = new TemplateRenderer(),
    ?callable $searchRunner = NULL,
  ) {
    $this->searchRunner = \Closure::fromCallable($searchRunner ?? SearchBlock::vars(...));
  }

  /**
   * @param array<string, mixed> $params
   * @return array{body: string, subject: ?string, blocks: int, count: int}
   */
  public function compose(array $params): array {
    $blocks = BlockList::parse($params);
    // The header is HTML; a job saved through the form stores it input-encoded.
    $parts = [trim(TemplateRenderer::decodeInput(Cast::toString($params['header'] ?? '')))];
    $subject = NULL;
    $count = 0;
    foreach ($blocks as $block) {
      $rendered = $this->renderBlock($block + ['period' => $params['period'] ?? 'yesterday'], $params);
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
   * @param array<string, mixed> $params The digest's own params, for section listeners
   * @return array{body: string, subject: ?string, count: int}
   */
  private function renderBlock(array $block, array $params): array {
    $type = Cast::toString($block['type'] ?? 'contribution');
    if ($type === 'section') {
      $body = ExtraSections::answer(Cast::toString($block['key']), ['params' => $params, 'period' => $block['period']]);
      return ['body' => $body, 'subject' => NULL, 'count' => 0];
    }
    $vars = match ($type) {
      'contribution' => DigestVars::build($block, $this->stats),
      'search' => ($this->searchRunner)(Cast::toString($block['search'])),
    };
    $vars['block_title'] = Cast::toString($block['title'] ?? '');
    $template = Cast::toString($block['template'] ?? '');
    $parts = $this->renderer->renderParts($template !== '' ? $template : self::DEFAULT_TEMPLATES[$type], $vars);
    return [
      'body' => trim($parts['body']),
      'subject' => $parts['subject'],
      'count' => Cast::toInt($vars['totals']['count'] ?? $vars['count'] ?? 0),
    ];
  }

}
