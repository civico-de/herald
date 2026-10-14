<?php

declare(strict_types = 1);

use Civi\Herald\Digest\DigestProblem;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * One block of an old inline digest: its sources and its template text.
 */
final class CRM_Herald_Upgrader_InlineBlock {

  /**
   * Block templates herald shipped; the migrated text replaces them.
   */
  private const SHIPPED_TEMPLATES = [
    'herald_contribution_digest_block',
    'herald_digest_search_block',
    'herald_contribution_digest_daily',
    'herald_contribution_digest_weekly',
  ];

  /**
   * Throws for a block that cannot be migrated; notes a template that is not.
   *
   * @param array<string, mixed> $block
   * @throws DigestProblem
   */
  public static function check(array $block, string $digestName): void {
    self::checkReferences($block);
    self::noteTemplate($block, $digestName);
  }

  /**
   * The sources of one old block and its template text.
   *
   * @param array<string, mixed> $block
   * @return array{0: list<array<string, mixed>>, 1: string}
   */
  public static function sources(array $block, string $prefix, string $digestName, string $label): array {
    switch ($block['type']) {
      case 'search':
        $problem = CRM_Herald_Upgrader_InlineSearch::problem($block);
        if ($problem !== NULL) {
          throw new DigestProblem([$problem]);
        }
        $display = CRM_Herald_Upgrader_InlineSearch::display($block, $label);
        return [
          [['name' => $prefix, 'type' => 'search', 'search_display_id' => $display]],
          CRM_Herald_Upgrader_InlineTemplate::search($prefix, $display),
        ];

      case 'section':
        $key = Cast::toString($block['section_key'] ?? '');
        return [
          [['name' => $prefix, 'type' => 'section', 'section_key' => $key]],
          CRM_Herald_Upgrader_InlineTemplate::section($prefix, $key, $digestName),
        ];

      case 'contribution':
        $displays = CRM_Herald_Upgrader_InlineSources::contribution($block, $prefix, $label);
        return [
          array_map(
            static fn (string $name, int $display): array => [
              'name' => $name,
              'type' => 'search',
              'search_display_id' => $display,
            ],
            array_keys($displays),
            $displays,
          ),
          CRM_Herald_Upgrader_InlineTemplate::contribution($block, $prefix, $displays),
        ];

      default:
        throw new DigestProblem(['unknown block type "' . Cast::toString($block['type']) . '"']);
    }
  }

  /**
   * Custom block templates are not migrated, their blocks get the default text.
   *
   * @param array<string, mixed> $block
   */
  private static function noteTemplate(array $block, string $digestName): void {
    $template = $block['template'] ?? NULL;
    if ($template !== NULL && !in_array($template, self::SHIPPED_TEMPLATES, TRUE)) {
      \Civi::log('herald')->warning('herald: a digest block template was replaced with the default text', [
        'digest' => $digestName,
        'template' => $template,
      ]);
    }
  }

  /**
   * A campaign or financial type deleted since: its filter matched nothing, dropping it would match everything.
   *
   * @param array<string, mixed> $block
   */
  private static function checkReferences(array $block): void {
    // The tables, not the API: Campaign has no table name while CiviCampaign is disabled.
    foreach ([
      'campaign_id' => 'civicrm_campaign',
      'financial_type_id' => 'civicrm_financial_type',
    ] as $field => $table) {
      $id = Cast::toInt($block[$field] ?? 0);
      if (
        $id > 0
        && !CRM_Utils_SQL_Select::from($table)->select('id')->where('id = #id', ['id' => $id])->execute()->fetchValue()
      ) {
        throw new DigestProblem(["{$field} {$id} does not exist"]);
      }
    }
  }

}
