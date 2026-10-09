<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

/**
 * Reads the trigger context's `original` map: pre-edit values keyed by the
 * entity name the trigger used.
 */
final class OriginalValues {

  /**
   * Pre-edit value of one field, NULL when the trigger recorded none.
   *
   * @param array<string, mixed> $context
   */
  public static function field(array $context, string $entity, string $field): mixed {
    $original = $context['original'] ?? NULL;
    // Triggers spell the entity as the hook's objectName; match it case-insensitively.
    foreach (is_array($original) ? $original : [] as $name => $values) {
      if (strcasecmp((string) $name, $entity) === 0 && is_array($values)) {
        return $values[$field] ?? NULL;
      }
    }
    return NULL;
  }

}
