<?php

declare(strict_types = 1);

use Civi\Herald\Digest\BucketEdges;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The HeraldDigestBlock values of a contribution block in the parameters of older releases.
 */
final class CRM_Herald_Upgrader_InlineContributionBlock {

  private const DEFAULTS = [
    'campaign_id' => 0,
    'financial_type_id' => 0,
    'group_by' => 'source',
    'show_pending' => TRUE,
    'show_histogram' => TRUE,
    'bucket_count' => 8,
    'bucket_edges' => '',
  ];

  /**
   * @param array<string, mixed> $block
   * @return array<string, mixed>
   */
  public static function values(array $block, string $digestName): array {
    $block = array_filter($block, static fn (mixed $value): bool => $value !== NULL) + self::DEFAULTS;
    return [
      'campaign_id' => self::id($block['campaign_id']),
      'financial_type_id' => self::id($block['financial_type_id']),
      'source_pattern' => CRM_Herald_Upgrader_InlineDigestBlocks::text($block, 'source_pattern'),
      'group_by' => $block['group_by'] === 'line_item' ? 'line_item' : 'source',
      // Older forms stored "0" or "" for an unchecked box.
      'show_pending' => (bool) $block['show_pending'],
      'show_histogram' => (bool) $block['show_histogram'],
      'bucket_count' => self::id($block['bucket_count']) ?? 8,
      'bucket_edges' => self::edges($block['bucket_edges'], $digestName),
    ];
  }

  /**
   * A positive id or count; 0 and "" mean none.
   */
  private static function id(mixed $value): ?int {
    $id = Cast::toInt($value);
    return $id === 0 ? NULL : $id;
  }

  /**
   * The stored form of bucket edges. Older releases drew adaptive buckets over
   * an empty list and over edges they could not read; the record keeps that.
   */
  private static function edges(mixed $edges, string $digestName): ?string {
    $edges = is_array($edges) ? json_encode($edges, JSON_THROW_ON_ERROR) : trim(Cast::toString($edges));
    if ($edges === '' || $edges === '[]') {
      return NULL;
    }
    $problem = BucketEdges::problem($edges);
    if ($problem === NULL) {
      return $edges;
    }
    \Civi::log('herald')->warning('herald: unreadable bucket edges dropped, the histogram stays adaptive', [
      'digest' => $digestName,
      'bucket_edges' => $edges,
      'problem' => $problem,
    ]);
    return NULL;
  }

}
