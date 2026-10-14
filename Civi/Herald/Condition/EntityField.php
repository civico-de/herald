<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use CRM_Herald_ExtensionUtil as E;

/**
 * Checks that APIv4 can read a field of an entity, as the field-transition
 * condition does: custom fields as group.field, option fields with a suffix such as :name.
 */
final class EntityField {

  /**
   * @return array<'entity'|'field', string> the error on the entity or the field; [] when readable
   */
  public static function errors(string $entity, string $field): array {
    $parts = explode(':', $field, 2);
    $name = $parts[0];
    $suffix = $parts[1] ?? NULL;
    try {
      $join = FieldSpec::join($entity, $name);
      if ($join !== NULL) {
        $errors = self::errors($join['entity'], $join['rest'] . ($suffix === NULL ? '' : ":{$suffix}"));
        // The entity was readable, so behind a join only the field name can be wrong.
        return $errors === [] ? [] : ['field' => implode(' ', $errors)];
      }
      $spec = FieldSpec::get($entity, $name, ['name', 'type', 'suffixes']);
    }
    catch (\CRM_Core_Exception $e) {
      return ['entity' => E::ts('"%1" cannot be checked: %2', [1 => $entity, 2 => $e->getMessage()])];
    }
    // getFields matches names case-insensitively, a select does not.
    if (!is_array($spec) || $spec['name'] !== $name) {
      return ['field' => E::ts('%1 has no field "%2".', [1 => $entity, 2 => $name])];
    }
    if ($spec['type'] === 'Filter') {
      return ['field' => E::ts('%1.%2 is a search filter and holds no value.', [1 => $entity, 2 => $name])];
    }
    $suffixes = is_array($spec['suffixes'] ?? NULL) ? $spec['suffixes'] : [];
    return (
      $suffix === NULL || in_array($suffix, $suffixes, TRUE)
        ? []
        : ['field' => E::ts('%1.%2 has no option suffix ":%3".', [1 => $entity, 2 => $name, 3 => $suffix])]
    );
  }

}
