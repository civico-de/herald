<?php

declare(strict_types = 1);

namespace Civi\Api4;

/**
 * Digests composed by Herald.notifyDigest and the CiviRules action "Send digest".
 */
final class HeraldDigest extends Generic\DAOEntity {

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
