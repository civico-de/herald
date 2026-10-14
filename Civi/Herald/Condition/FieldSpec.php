<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

/**
 * Looks up APIv4 field specs and the foreign key a field name starts with.
 */
final class FieldSpec {

  /**
   * A field of the entity wins over a join, as in an APIv4 select. A custom
   * field is group.field, so a foreign key can span two segments.
   *
   * @return array{entity: string, rest: string}|null the referenced entity and the rest of the name
   */
  public static function join(string $entity, string $name): ?array {
    $names = civicrm_api4($entity, 'getFields', ['checkPermissions' => FALSE, 'select' => ['name']])->column('name');
    if (in_array($name, $names, TRUE)) {
      return NULL;
    }
    $parts = explode('.', $name);
    for ($length = 1; $length < min(3, count($parts)); $length++) {
      $head = implode('.', array_slice($parts, 0, $length));
      $fk = in_array($head, $names, TRUE) ? self::get($entity, $head, ['fk_entity'])['fk_entity'] ?? NULL : NULL;
      if (is_string($fk)) {
        return ['entity' => $fk, 'rest' => implode('.', array_slice($parts, $length))];
      }
    }
    return NULL;
  }

  /**
   * @param list<string> $select
   * @return array<array-key, mixed>|null
   */
  public static function get(string $entity, string $name, array $select): ?array {
    $spec = civicrm_api4($entity, 'getFields', [
      'checkPermissions' => FALSE,
      'where' => [['name', '=', $name]],
      'select' => $select,
    ])->first();
    return is_array($spec) ? $spec : NULL;
  }

}
