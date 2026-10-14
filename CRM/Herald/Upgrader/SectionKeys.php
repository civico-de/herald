<?php

declare(strict_types = 1);

/**
 * The `sections` parameter of old contribution digest jobs.
 */
final class CRM_Herald_Upgrader_SectionKeys {

  /**
   * "a.b, c.d" or a JSON/PHP array.
   *
   * @return list<string>
   */
  public static function parse(mixed $raw): array {
    if (is_string($raw)) {
      $decoded = str_starts_with(trim($raw), '[') ? json_decode($raw, TRUE) : NULL;
      $raw = is_array($decoded) ? $decoded : explode(',', $raw);
    }
    $keys = array_map(
      static fn (mixed $key): string => is_scalar($key) ? trim((string) $key) : '',
      is_array($raw) ? $raw : [],
    );
    return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
  }

}
