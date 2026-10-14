<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The digest block types, the HeraldDigestBlock field each cannot do without,
 * and the checks a block passes before it is saved or sent.
 */
final class BlockType {

  public const REQUIRED = ['contribution' => NULL, 'search' => 'saved_search_id', 'section' => 'section_key'];

  /**
   * Why the block cannot run, or NULL.
   *
   * @param array<string, mixed> $block HeraldDigestBlock values
   */
  public static function problem(array $block): ?string {
    $type = Cast::toString($block['type'] ?? 'contribution');
    if (!array_key_exists($type, self::REQUIRED)) {
      return E::ts('unknown type "%1".', [1 => $type]);
    }
    $field = self::REQUIRED[$type];
    if ($field !== NULL && trim(Cast::toString($block[$field] ?? '')) === '') {
      return $type === 'search'
        ? E::ts('a saved search block needs a saved search.')
        : E::ts('a section block needs a section key.');
    }
    return (
      Options::problem(E::ts('period'), $block['period'] ?? NULL, Options::periods())
      ?? ($type === 'contribution' ? ContributionBlock::problem($block) : NULL)
    );
  }

}
