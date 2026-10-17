<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * Whether a composed digest has nothing to report: it has sources and each is
 * a search without rows. A section answers with values, not rows, and never counts.
 *
 * @phpstan-import-type Digest from Definition
 */
final class NothingToReport {

  /**
   * @param Digest $digest
   * @param array<string, array<string, mixed>> $values Source values by name, as Composer::values() returns them
   */
  public static function in(array $digest, array $values): bool {
    foreach ($digest['sources'] as $source) {
      if ($source['type'] !== 'search' || ($values[$source['name']][Placeholders::ROWS] ?? NULL) !== []) {
        return FALSE;
      }
    }
    return $digest['sources'] !== [];
  }

}
