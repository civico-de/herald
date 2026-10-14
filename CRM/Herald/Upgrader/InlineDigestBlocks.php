<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The digest blocks in the parameters of older releases: a `blocks`
 * JSON list, or the top-level params as the one block.
 */
final class CRM_Herald_Upgrader_InlineDigestBlocks {

  /**
   * The blocks in order, or why they do not parse.
   *
   * @param array<string, mixed> $params
   * @return list<array<string, mixed>>|string
   */
  public static function parse(array $params, string $digestName): array|string {
    $raw = $params['blocks'] ?? [];
    // The old form saved an empty field as "".
    $raw = is_string($raw) ? json_decode(trim($raw) === '' ? '[]' : $raw, TRUE) : $raw;
    if (!is_array($raw) || array_filter($raw, is_array(...)) !== $raw) {
      return 'blocks are not a JSON array of objects';
    }
    // Without a list the params are the one block, which shares the digest's period.
    $raw = $raw === [] ? [array_diff_key($params, ['period' => TRUE])] : array_values($raw);
    if (array_filter($raw, CRM_Herald_Upgrader_InlineStatus::readable(...)) !== $raw) {
      return 'a contribution status filter other than Completed is no longer available';
    }
    return array_map(static fn (array $block): array => self::block(Cast::namedOnly($block), $digestName), $raw);
  }

  /**
   * @param array<string, mixed> $block
   * @return array<string, mixed>
   */
  private static function block(array $block, string $digestName): array {
    $type = Cast::toString($block['type'] ?? 'contribution');
    return (
      [
        'type' => $type,
        'title' => self::text($block, 'title'),
        // A block period set to anything, even blank, replaced the digest's.
        'period' => array_key_exists('period', $block)
          ? CRM_Herald_Upgrader_InlinePeriod::read($block['period'], $digestName)
          : NULL,
        'template' => self::text($block, 'template'),
      ]
      + match ($type) {
        'search' => ['saved_search' => Cast::toString($block['search'] ?? '')],
        'section' => ['section_key' => self::text($block, 'key')],
        default => CRM_Herald_Upgrader_InlineContributionBlock::values($block, $digestName),
      }
    );
  }

  /**
   * @param array<string, mixed> $block
   */
  private static function text(array $block, string $key): ?string {
    $value = trim(Cast::toString($block[$key] ?? ''));
    return $value === '' ? NULL : $value;
  }

}
