<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Source-entity scoping of per-contact subscriptions.
 *
 * A preference record may carry source_entity_type + source_entity_id
 * (e.g. Event 42): its subscriptions then only fire for notifications that
 * declare they concern that entity instance. An unscoped record (NULL/empty
 * fields) is global and matches everything — the historical behavior.
 * @mago-expect lint:cyclomatic-complexity
 */
final class SubscriptionScope {

  /**
   * Scope declared by the notification, from action params + trigger context.
   *
   * The action param `scope_entity` names a trigger-context entity; the id is
   * taken from that entity's payload. "Event" also resolves through a
   * Participant payload's event_id, so participant triggers can scope to
   * their event.
   *
   * @param array<string, mixed> $actionParams
   * @param array<string, mixed> $triggerContext
   * @return array{entity_type: string, entity_id: int}|null NULL = unscoped notification.
   */
  public static function fromContext(array $actionParams, array $triggerContext): ?array {
    $entityType = Cast::toString($actionParams['scope_entity'] ?? '');
    if ($entityType === '') {
      return NULL;
    }
    $payload = $triggerContext[$entityType] ?? NULL;
    $id = is_array($payload) ? Cast::toInt($payload['id'] ?? 0) : 0;
    if ($id <= 0 && $entityType === 'Event') {
      $participant = $triggerContext['Participant'] ?? NULL;
      $id = is_array($participant) ? Cast::toInt($participant['event_id'] ?? 0) : 0;
    }
    return $id > 0 ? ['entity_type' => $entityType, 'entity_id' => $id] : NULL;
  }

  /**
   * Does a preference row's scope admit a notification with the given scope?
   *
   * Unscoped row → always yes. Scoped row → only when the notification
   * declares the same entity instance; an unscoped notification never reaches
   * scoped subscriptions.
   *
   * @param array<string, mixed> $row APIv4 Contact row with herald_preferences.* fields.
   * @param array{entity_type: string, entity_id: int}|null $scope
   */
  public static function rowMatches(array $row, ?array $scope): bool {
    $rowType = Cast::toString($row['herald_preferences.source_entity_type'] ?? '');
    $rowId = Cast::toInt($row['herald_preferences.source_entity_id'] ?? 0);
    if ($rowType === '' && $rowId <= 0) {
      return TRUE;
    }
    if ($scope === NULL) {
      return FALSE;
    }
    return $rowType === $scope['entity_type'] && $rowId === $scope['entity_id'];
  }

}
