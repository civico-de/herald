<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

/**
 * Looks up APIv4 field specs, following a name that starts with a foreign key.
 */
final class FieldSpec {

  /**
   * getFields echoes a joined name in whatever case it was given, so only an
   * exactly named foreign key counts as a join.
   *
   * @return array{entity: string, rest: string}|null the referenced entity and the rest of the name
   */
  public static function join(string $entity, string $name): ?array {
    if (!str_contains($name, '.')) {
      return NULL;
    }
    [$head, $rest] = explode('.', $name, 2);
    $spec = self::get($entity, $head, ['name', 'fk_entity']);
    return (
      is_array($spec) && $spec['name'] === $head && is_string($spec['fk_entity'] ?? NULL)
        ? ['entity' => $spec['fk_entity'], 'rest' => $rest]
        : NULL
    );
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
