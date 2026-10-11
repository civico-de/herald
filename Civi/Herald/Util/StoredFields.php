<?php

declare(strict_types = 1);

namespace Civi\Herald\Util;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Post-hook payloads of partial updates carry only the written fields; the
 * stored row supplies the missing ones.
 */
final class StoredFields {

  /**
   * $payload plus those of $fields it lacks, read by $get; unchanged when the
   * read fails, so the alert still goes out with fewer details.
   *
   * @param array<array-key, mixed> $payload
   * @param list<string> $fields
   * @return array<array-key, mixed>
   */
  public static function complete(DAOGetAction $get, array $payload, array $fields): array {
    $missing = array_values(array_filter(
      $fields,
      static fn (string $field): bool => !array_key_exists($field, $payload),
    ));
    $id = Cast::toInt($payload['id'] ?? 0);
    if ($missing === [] || $id <= 0) {
      return $payload;
    }
    try {
      $stored = $get->setSelect($missing)->addWhere('id', '=', $id)->execute()->first();
    }
    catch (\Throwable $e) {
      \Civi::log()->error('herald: could not read the stored ' . $get->getEntityName() . ' row', [
        'id' => $id,
        'error' => $e->getMessage(),
      ]);
      return $payload;
    }
    return is_array($stored) ? $payload + $stored : $payload;
  }

}
