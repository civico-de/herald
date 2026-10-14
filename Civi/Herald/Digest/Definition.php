<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * A stored digest (HeraldDigest) with its blocks in id order, the order the
 * Afform editor shows and saves them in.
 */
final class Definition {

  /**
   * @return array{name: string, header: string, period: string, blocks: list<array<string, mixed>>}
   */
  public static function load(string $name): array {
    $digest = \Civi\Api4\HeraldDigest::get(checkPermissions: FALSE)
      ->addSelect('id', 'name', 'header', 'period')
      ->addWhere('name', '=', $name)
      ->execute()
      ->first();
    if (!is_array($digest)) {
      throw new \CRM_Core_Exception(E::ts('Digest "%1" does not exist.', [1 => $name]));
    }
    /** @var list<array<string, mixed>> $rows */
    $rows = \Civi\Api4\HeraldDigestBlock::get(checkPermissions: FALSE)
      ->addWhere('digest_id', '=', $digest['id'])
      ->addOrderBy('id')
      ->execute()
      ->getArrayCopy();
    $blocks = [];
    foreach ($rows as $row) {
      unset($row['id'], $row['digest_id']);
      // An empty field means the default: the digest's period, the type's template.
      $blocks[] = array_filter($row, static fn (mixed $value): bool => $value !== NULL && $value !== '');
    }
    return [
      'name' => $name,
      'header' => Cast::toString($digest['header'] ?? ''),
      'period' => Cast::toString($digest['period'] ?? ''),
      'blocks' => $blocks,
    ];
  }

}
