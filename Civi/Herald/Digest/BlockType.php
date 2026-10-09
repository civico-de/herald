<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Util\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The digest block types and the key each cannot do without.
 */
final class BlockType {

  public const REQUIRED = ['contribution' => NULL, 'search' => 'search', 'section' => 'key'];

  /**
   * Why the block cannot run, or NULL.
   *
   * @param array<string, mixed> $block
   * @param callable(string): ?string $searchProblem
   */
  public static function problem(array $block, callable $searchProblem): ?string {
    $type = Cast::toString($block['type'] ?? 'contribution');
    if (!array_key_exists($type, self::REQUIRED)) {
      return E::ts('unknown type "%1".', [1 => $type]);
    }
    $key = self::REQUIRED[$type];
    $value = $key === NULL ? NULL : trim(Cast::toString($block[$key] ?? ''));
    if ($value === '') {
      return E::ts('a %1 block needs "%2".', [1 => $type, 2 => $key]);
    }
    return $type === 'search' ? $searchProblem((string) $value) : NULL;
  }

}
