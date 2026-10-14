<?php

declare(strict_types = 1);

namespace Civi\Api4;

/**
 * The sources of a HeraldDigest; validated on save by Civi\Herald\Digest\Validator.
 * Managed, so an extension can ship a digest with its sources in mgd files.
 */
final class HeraldDigestSource extends Generic\DAOEntity {

  use Generic\Traits\ManagedEntity;

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
