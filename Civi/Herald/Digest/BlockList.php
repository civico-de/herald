<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The blocks of a digest: parsed from the `blocks` param, or the top-level
 * params as the single block.
 */
final class BlockList {

  /**
   * The blocks, structurally checked; a missing saved search fails when it runs.
   *
   * @param array<string, mixed> $params
   * @return list<array<string, mixed>>
   */
  public static function parse(array $params): array {
    $problems = self::problems($params, static fn (): ?string => NULL);
    if ($problems !== []) {
      throw new \CRM_Core_Exception(implode(' ', $problems));
    }
    return self::decode($params);
  }

  /**
   * Configuration errors a form can show before saving; [] when the blocks are usable.
   *
   * @param array<string, mixed> $params
   * @param (callable(string): ?string)|null $searchProblem Default: the saved search must exist
   * @return list<string>
   */
  public static function problems(array $params, ?callable $searchProblem = NULL): array {
    try {
      $blocks = self::decode($params);
    }
    catch (\CRM_Core_Exception $e) {
      return [$e->getMessage()];
    }
    $problems = [];
    foreach ($blocks as $i => $block) {
      $problem = BlockType::problem($block, $searchProblem ?? SearchBlock::problem(...));
      if ($problem !== NULL) {
        $problems[] = E::ts('Block %1: %2', [1 => $i + 1, 2 => $problem]);
      }
    }
    return $problems;
  }

  /**
   * @param array<string, mixed> $params
   * @return list<array<string, mixed>>
   */
  private static function decode(array $params): array {
    $raw = $params['blocks'] ?? [];
    if (is_string($raw)) {
      $raw = trim($raw) === '' ? [] : json_decode($raw, TRUE);
    }
    if (!is_array($raw) || array_filter($raw, is_array(...)) !== $raw) {
      throw new \CRM_Core_Exception(E::ts('Blocks must be a JSON array of objects.'));
    }
    if ($raw === []) {
      return [$params];
    }
    return array_values(array_map(Cast::namedOnly(...), $raw));
  }

}
