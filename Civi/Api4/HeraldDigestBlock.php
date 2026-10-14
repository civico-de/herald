<?php

declare(strict_types = 1);

namespace Civi\Api4;

/**
 * The blocks of a HeraldDigest; validated on save by Civi\Herald\Digest\BlockValidator.
 */
final class HeraldDigestBlock extends Generic\DAOEntity {

  /**
   * @return array<string, array<int, string|array<int, string>>>
   */
  public static function permissions(): array {
    return [
      'meta' => ['administer CiviCRM'],
      'default' => ['administer CiviCRM'],
    ];
  }

}
