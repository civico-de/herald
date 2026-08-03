<?php

declare(strict_types = 1);

namespace Civi\Herald;

/**
 * Request-scoped store of pre-edit field values, captured in hook_civicrm_pre.
 *
 * hook_civicrm_post fires AFTER the row is written, so the old value is
 * otherwise unreachable — and a CiviRules trigger's own entity payload only
 * carries the fields that were passed to the write, which for a partial update
 * may omit the very field a condition wants to compare. Reading a stale value
 * as NULL and loose-comparing it (`null == 0`) is exactly how a "became
 * inactive" condition misfires on unrelated edits. Snapshotting the real
 * pre-edit value here removes that trap.
 *
 * First-wins per (entity, id, field) and request: a single logical operation
 * can edit a row several times (e.g. status write, then custom fields); the
 * earliest snapshot holds the genuine "before" value.
 */
class PreEditSnapshot {

  /**
   * @var array<string, mixed> keyed by "<entity>:<id>:<field>" */
  private static array $store = [];

  private static function key(string $entity, int $id, string $field): string {
    return $entity . ':' . $id . ':' . $field;
  }

  public static function remember(string $entity, int $id, string $field, mixed $value): void {
    $k = self::key($entity, $id, $field);
    if (!array_key_exists($k, self::$store)) {
      self::$store[$k] = $value;
    }
  }

  /**
   * @return mixed NULL when nothing was captured this request.
   */
  public static function get(string $entity, int $id, string $field): mixed {
    return self::$store[self::key($entity, $id, $field)] ?? NULL;
  }

  /**
   * Test seam.
   */
  public static function reset(): void {
    self::$store = [];
  }

}
