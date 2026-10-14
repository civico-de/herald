<?php

declare(strict_types = 1);

namespace Civi\Api4;

/**
 * Digests composed by Herald.notifyDigest and the CiviRules action "Send digest".
 * Managed, so an extension can ship a digest with its sources in mgd files.
 */
final class HeraldDigest extends Generic\DAOEntity {

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
