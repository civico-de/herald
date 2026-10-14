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
    $pattern = Cast::toString($block['source_pattern'] ?? '');
    return [
      'campaign_id' => self::id($block['campaign_id']),
      'financial_type_id' => self::id($block['financial_type_id']),
      // A LIKE pattern, used verbatim.
      'source_pattern' => $pattern === '' ? NULL : $pattern,
      'group_by' => $block['group_by'] === 'line_item' ? 'line_item' : 'source',
      // Older forms stored "0" or "" for an unchecked box.
      'show_pending' => !empty($block['show_pending']),
      'show_histogram' => !empty($block['show_histogram']),
      'bucket_count' => self::bucketCount($block, $digestName),
      'bucket_edges' => self::edges($block['bucket_edges'], $digestName),
    ];
  }

  /**
   * Older releases drew one bucket below 0 and failed on 0 once two amounts
   * differed; blank meant the default.
   *
   * @param array<string, mixed> $block
   */
  private static function bucketCount(array $block, string $digestName): int {
    $count = Cast::toInt($block['bucket_count'], 8);
    if ($count !== 0) {
      return max(1, $count);
    }
    if (!empty($block['show_histogram'])) {
      \Civi::log('herald')->warning('herald: histogram bucket count 0 replaced with the default 8', [
        'digest' => $digestName,
      ]);
    }
    return 8;
  }

  private static function number(mixed $value): mixed {
    return is_string($value) && is_numeric($value) ? Cast::toFloat($value) : $value;
  }

  /**
   * A positive id; 0 and "" mean none.
   */
  private static function id(mixed $value): ?int {
    $id = Cast::toInt($value);
    return $id === 0 ? NULL : $id;
  }

  /**
   * The stored form of bucket edges. Older releases read numeric strings as
   * numbers and drew adaptive buckets over an empty list or unreadable JSON.
   */
  private static function edges(mixed $edges, string $digestName): ?string {
    $text = is_array($edges) ? '[]' : trim(Cast::toString($edges));
    $decoded = is_array($edges) ? $edges : json_decode($text, TRUE);
    if ($text === '' || $decoded === []) {
      return NULL;
    }
    $edges = is_array($decoded) ? json_encode(array_map(self::number(...), $decoded), JSON_THROW_ON_ERROR) : $text;
    $problem = BucketEdges::problem($edges);
    if ($problem === NULL) {
      return $edges;
    }
    \Civi::log('herald')->warning('herald: unusable bucket edges dropped, the histogram uses adaptive buckets', [
      'digest' => $digestName,
      'bucket_edges' => $edges,
      'problem' => $problem,
    ]);
    return NULL;
  }

}
