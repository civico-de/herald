<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The search displays an old contribution block becomes
 * (CRM_Herald_Upgrader_SearchCopy), named after the block.
 */
final class CRM_Herald_Upgrader_InlineSources {

  private const SHIPPED = [
    'totals' => 'Herald_Digest_Contributions_Yesterday',
    'by_form' => 'Herald_Digest_Contributions_Yesterday_By_Form',
    'line_items' => 'Herald_Digest_Line_Items_Yesterday',
    'amounts' => 'Herald_Digest_Contribution_Amounts_Yesterday',
    'pending' => 'Herald_Digest_Contributions_Pending',
  ];

  /**
   * Source name => search display id, the totals under $prefix itself.
   *
   * @param array<string, mixed> $block As CRM_Herald_Upgrader_InlineDigestBlocks parsed it, with its period
   * @return array<string, int>
   */
  public static function contribution(array $block, string $prefix, string $label): array {
    $period = Cast::toString($block['period']);
    $filters = array_values(array_filter([
      isset($block['campaign_id']) ? ['campaign_id', '=', $block['campaign_id']] : NULL,
      isset($block['financial_type_id']) ? ['financial_type_id', '=', $block['financial_type_id']] : NULL,
      isset($block['source_pattern']) ? ['source', 'LIKE', $block['source_pattern']] : NULL,
    ]));
    $search = static fn (string $kind, ?string $period, string $suffix = ''): int => CRM_Herald_Upgrader_SearchCopy::of(
      self::SHIPPED[$kind],
      $period,
      $filters,
      $prefix . $suffix,
      $label,
    );
    // Line-item blocks totalled their items, so a count is the quantity sold.
    $items = $block['group_by'] === 'line_item';
    $totals = static fn (string $status, ?string $period, string $suffix = ''): int => $items
      ? CRM_Herald_Upgrader_LineItemSearch::totals($status, $period, $filters, $prefix . $suffix, $label)
      : $search($status === 'Pending' ? 'pending' : 'totals', $period, $suffix);
    $sources = [
      $prefix => $totals('Completed', $period),
      "{$prefix}_breakdown" => $search($items ? 'line_items' : 'by_form', $period),
    ];
    if ($block['show_pending']) {
      $sources["{$prefix}_pending"] = $totals('Pending', NULL, '_pending');
    }
    if ($block['show_histogram']) {
      $sources["{$prefix}_amounts"] = $search('amounts', $period, '_amounts');
    }
    if (isset($block['campaign_id'])) {
      // Progress counts the whole campaign, regardless of the period.
      $sources["{$prefix}_campaign_total"] = $totals('Completed', NULL, '_campaign_total');
      $sources["{$prefix}_campaign"] = CRM_Herald_Upgrader_SearchCopy::create(
        "{$label}: " . E::ts('campaign goal'),
        'Campaign',
        ['select' => ['goal_revenue'], 'where' => [['id', '=', $block['campaign_id']]]],
        ['columns' => [['type' => 'field', 'key' => 'goal_revenue', 'label' => E::ts('Goal')]]],
      );
    }
    return $sources;
  }

}
