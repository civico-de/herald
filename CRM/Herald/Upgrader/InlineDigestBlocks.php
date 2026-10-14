<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * HeraldDigestBlock values from the parameters of older releases: a `blocks`
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
    return array_map(static fn (array $block): array => self::block(Cast::namedOnly($block), $digestName), $raw);
  }

  /**
   * @param array<string, mixed> $block
   * @return array<string, mixed>
   */
  private static function block(array $block, string $digestName): array {
    $type = self::text($block, 'type') ?? 'contribution';
    return (
      [
        'type' => $type,
        'title' => self::text($block, 'title'),
        'period' => self::text($block, 'period'),
        'template' => self::text($block, 'template'),
      ]
      + match ($type) {
        'search' => ['saved_search_id' => self::savedSearchId(Cast::toString($block['search'] ?? ''))],
        'section' => ['section_key' => self::text($block, 'key')],
        default => CRM_Herald_Upgrader_InlineContributionBlock::values($block, $digestName),
      }
    );
  }

  /**
   * @param array<string, mixed> $block
   */
  public static function text(array $block, string $key): ?string {
    $value = trim(Cast::toString($block[$key] ?? ''));
    return $value === '' ? NULL : $value;
  }

  private static function savedSearchId(string $name): ?int {
    $id =
      \Civi\Api4\SavedSearch::get(checkPermissions: FALSE)
        ->addSelect('id')
        ->addWhere('name', '=', $name)
        ->execute()
        ->first()['id'] ?? NULL;
    return $id === NULL ? NULL : Cast::toInt($id);
  }

}
